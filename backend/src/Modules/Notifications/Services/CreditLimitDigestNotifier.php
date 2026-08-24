<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\Notifications\Domain\DigestWindow;
use App\Modules\Notifications\DTOs\CreditLimitDigestScanResultDto;
use App\Modules\Notifications\Enums\MailKind;
use App\Shared\Logging\Logger;
use DateTimeImmutable;

/**
 * Tells the Kassenwart which members are close to their Deckel ceiling, without
 * waiting for them to open the admin panel (ADR-0047, migration 054).
 *
 * ## Why this exists at all
 *
 * The dashboard's near-limit panel (#385) answers the question the moment
 * somebody asks it, and is completely silent to a treasurer who does not open
 * the panel. The failure it is silent about is small but real and lands on the
 * wrong person: a member is refused at the bar, on an evening, offline, with a
 * queue behind them — and the first the club hears of it is the member saying
 * so. A weekly list of who is heading that way is enough to make it not happen.
 *
 * This is the same argument {@see CredentialExpiryNotifier} makes about
 * credentials, applied to money, and it rides the same tick for the same
 * reason: ADR-0038 made a scheduler mandatory, so an installation that can send
 * mail at all has one.
 *
 * ## One mail, not one per member
 *
 * The request that motivated this asked for an aggregate, and the aggregate is
 * also the design that survives contact with a busy club. A per-member notice
 * would put twelve mails in the treasurer's inbox on a Saturday, each naming
 * one person, none of them showing the shape of the problem — and it would put
 * member names into the queue, where the digest keeps them out entirely.
 *
 * So the subject is the club's credit-limit configuration, the content is
 * rebuilt at send time, and the queue row carries nothing but addressing and a
 * window key.
 *
 * ## Idempotency
 *
 * The same answer as ADR-0039's statements and #438's expiry warnings: **the
 * window goes in the `dedup_key`, and the unique index decides.** Nothing here
 * selects to find out whether it has already sent this week's digest. It
 * offers a message and counts what the database accepted — faster, and correct
 * under two overlapping ticks where a lookup-then-insert would have both passes
 * find nothing and both insert.
 *
 * ## Silence when there is nothing to say
 *
 * A digest naming nobody is not queued. That is not an optimisation; it is what
 * keeps the mail worth opening. A recipient who gets "0 members near their
 * limit" fifty times a year has learned to file it unread by the time the
 * fifty-first says eleven.
 *
 * The consequence, stated plainly: **this feature is silent by design most of
 * the time**, and "I received no digest" means "nobody is near their ceiling",
 * not "the scheduler is broken". The scheduler's own health is the heartbeat's
 * job (ADR-0038), which is where somebody should look instead.
 *
 * ## Staying optional
 *
 * Nothing is queued on an installation with no mail configured, for the reason
 * {@see CredentialExpiryNotifier} spells out: {@see \App\Shared\Mail\NullTransport}
 * records a **permanent failure**, so every digest would land in the
 * Notifications page as a red row on an installation whose owner never asked
 * for mail. Better to queue nothing.
 *
 * Never throws. The caller is the cron tick, whose other job is draining the
 * queue, and a scan that could not read a table must not stop the club's
 * announcements from going out.
 */
class CreditLimitDigestNotifier
{
    public function __construct(
        private CreditLimitDigestService $digestService,
        private AdminNotifier $adminNotifier,
        private MailConfigService $mailConfigService,
        private Logger $logger,
    ) {}

    /**
     * The singleton `credit_limit_config` row — what a digest is *about*
     * ({@see \App\Modules\Notifications\Enums\MailSubject::CREDIT_LIMIT_CONFIG}).
     *
     * A literal rather than a value read from the table, because the table has
     * exactly one row by construction (migration 052) and reading it would
     * make the subject of a message depend on a query that can fail.
     */
    public const SUBJECT_ID = '1';

    /**
     * @param DateTimeImmutable|null $now Passed in, never read from the clock —
     *                                    the testability seam for the windows.
     */
    public function run(?DateTimeImmutable $now = null): CreditLimitDigestScanResultDto
    {
        $now ??= new DateTimeImmutable();

        try {
            $config = $this->mailConfigService->getConfig();
            $cadence = $config->creditLimitDigestCadence;

            if (!$cadence->isEnabled()) {
                return CreditLimitDigestScanResultDto::nothingDue('cadence off');
            }

            if (!$this->mailConfigService->canSend()) {
                return CreditLimitDigestScanResultDto::nothingDue('mail not configured');
            }

            $window = DigestWindow::containing($cadence, $now);
            if ($window === null) {
                // Unreachable while `isEnabled()` guards the call above, and
                // stated anyway rather than asserted away: a cadence added
                // later without a window arm would otherwise reach
                // `warnAdmins()` with a null occasion.
                return CreditLimitDigestScanResultDto::nothingDue('no window for a ' . $cadence->value . ' cadence');
            }

            // Collected *before* queueing, which is the whole of the "say
            // nothing when there is nothing to say" rule. It costs one query on
            // a tick that has already decided the cadence is on — every
            // fifteen minutes at worst, over a list the dashboard runs on every
            // page load.
            $report = $this->digestService->collect();

            if ($report->isEmpty()) {
                return CreditLimitDigestScanResultDto::nothingDue('nobody near their limit', $window->key);
            }

            $result = $this->adminNotifier->warnAdmins(
                MailKind::CREDIT_LIMIT_DIGEST,
                self::SUBJECT_ID,
                $window->key,
            );

            if ($result->queued > 0) {
                // One line per pass that queued something, never one per
                // recipient. The context is the report plus the enqueue
                // result, so the log and the caller cannot describe the same
                // pass differently.
                $this->logger->info('Credit limit digest queued', [
                    'window' => $window->key,
                    'cadence' => $cadence->value,
                    'queued' => $result->queued,
                ] + $report->toArray());
            }

            return new CreditLimitDigestScanResultDto(
                window: $window->key,
                membersNearLimit: $report->count() + $report->omitted,
                queued: $result->queued,
                alreadyQueued: $result->alreadyQueued,
                recipientsWithoutEmail: count($result->withoutEmail),
            );
        } catch (\Throwable $e) {
            $this->logger->error('Credit limit digest scan failed', ['error' => $e->getMessage()]);

            return CreditLimitDigestScanResultDto::nothingDue('scan failed: ' . $e->getMessage());
        }
    }
}
