<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\Notifications\Domain\StatementPeriod;
use App\Modules\Notifications\DTOs\MailRequestDto;
use App\Modules\Notifications\DTOs\PeriodicEnqueueResultDto;
use App\Modules\Notifications\Enums\MailLanguage;
use App\Modules\Notifications\Repositories\MailOutboxRepository;
use App\Modules\Notifications\Repositories\StatementRecipientsRepository;
use App\Shared\Logging\Logger;
use DateTimeImmutable;
use PDOException;

/**
 * The half of the tick that queues a Deckelauszug (ADR-0039 decision 1).
 *
 * Every other enqueue in this system happens *inside a business transaction* —
 * a settlement, its items and its announcements commit together, which is what
 * makes a half-finalize impossible. This one has no transaction to join. It is a
 * scan over members, run because a date passed.
 *
 * ADR-0038's framing survives the change intact, because the part that mattered
 * was never the transaction: *"idempotency lives on the message, not on the
 * settlement"* was always about `UNIQUE (kind, subject_id, dedup_key)` refusing
 * a duplicate without a lookup-then-insert race, and that constraint does not
 * care what caused the insert. So this service needs no cursor, no resume state
 * and no run entity.
 *
 * Three properties follow, and each is deliberate:
 *
 * 1. **It never `SELECT`s to see whether it already enqueued.** It inserts, and
 *    the index answers. A lookup would be both slower and wrong — two ticks
 *    overlapping would both find nothing and both insert.
 * 2. **It opens no transaction.** Wrapping the scan would turn a harmless
 *    partial pass into a long-held lock on a mass-hosting database, buying
 *    atomicity nobody needs: a pass killed halfway is completed by the next
 *    tick at no cost, because the rows it did write are exactly the rows the
 *    next pass will skip.
 * 3. **It is safe to run as often as you like.** Hourly against a monthly
 *    cadence means it does nothing almost every time, which is the intended
 *    shape rather than waste.
 */
class PeriodicEnqueueService
{
    /** SQLSTATE class for an integrity constraint violation. */
    private const INTEGRITY_VIOLATION = '23000';

    public function __construct(
        private MailConfigService $mailConfigService,
        private StatementRecipientsRepository $statementRecipients,
        private MailOutboxRepository $mailOutboxRepository,
        private Logger $logger,
    ) {}

    /**
     * Queue this period's statements, if one is due.
     *
     * @param DateTimeImmutable $now      Passed in, never read from the clock — the
     *                                    testability seam for the whole feature.
     * @param string|null       $periodKey `--period` from the command line. Names the
     *                                    period explicitly instead of deriving it.
     */
    public function run(DateTimeImmutable $now, ?string $periodKey = null): PeriodicEnqueueResultDto
    {
        $cadence = $this->mailConfigService->getConfig()->statementCadence;

        if (!$cadence->isEnabled()) {
            return PeriodicEnqueueResultDto::nothingDue('cadence off');
        }

        $period = $periodKey === null
            ? StatementPeriod::containing($cadence, $now)
            : StatementPeriod::fromKey($cadence, $periodKey);

        if ($period === null) {
            return PeriodicEnqueueResultDto::nothingDue('unreadable period for a ' . $cadence->value . ' cadence');
        }

        // The catch-up cap (ADR-0039). A scheduler that was off for a year must
        // not produce twelve statements per member on the day it returns, so a
        // period that is not the one we are in is simply missed.
        //
        // This applies to an explicitly named period too, and that is the point
        // of naming one: `--period` is how an operator re-runs a period they
        // believe was missed, and the check is what tells them it is too late
        // instead of quietly mailing a boundary from last spring. Re-running the
        // *current* period is safe by construction — the unique index makes the
        // second pass a no-op, which is why the flag needs no other guard.
        if (!$period->isCurrentAt($now)) {
            return PeriodicEnqueueResultDto::nothingDue('period is not current', $period->key());
        }

        return $this->enqueueFor($period);
    }

    private function enqueueFor(StatementPeriod $period): PeriodicEnqueueResultDto
    {
        $key = $period->key();
        $recipients = $this->statementRecipients->findForBoundary($period->boundary());

        $queued = 0;
        $alreadyQueued = 0;
        $skipped = 0;

        foreach ($recipients as $member) {
            $request = MailRequestDto::forStatement(
                memberId: (string) $member['id'],
                recipient: (string) $member['email'],
                language: MailLanguage::fromPreferred($member['preferred_language'] ?? null),
                period: $key,
            );

            try {
                if ($this->mailOutboxRepository->enqueue($request)) {
                    $queued++;
                } else {
                    $alreadyQueued++;
                }
            } catch (PDOException $e) {
                // The scan runs outside a transaction, so the member list it is
                // walking is a few milliseconds old by the time each insert
                // lands. A member deleted in that window takes their foreign key
                // with them, and the insert fails with the same SQLSTATE class
                // the dedup index would use. That is ordinary, not an error: the
                // member is gone, and nobody is owed a statement.
                //
                // Anything else propagates. A queue that swallowed every
                // database error would report a clean pass over a table it never
                // wrote to.
                if ($e->getCode() !== self::INTEGRITY_VIOLATION) {
                    throw $e;
                }

                $skipped++;
            }
        }

        $result = new PeriodicEnqueueResultDto(
            period: $key,
            queued: $queued,
            alreadyQueued: $alreadyQueued,
            skipped: $skipped,
        );

        if ($queued > 0 || $skipped > 0) {
            // One line for the pass, never one per member: a silent skip is
            // silent (ADR-0039 decision 3), and a club of three hundred would
            // otherwise write three hundred log lines a month saying nothing.
            // The context is the returned result, so the log and the caller
            // cannot describe the same pass differently.
            $this->logger->info('Deckel statements enqueued', $result->toArray());
        }

        return $result;
    }
}
