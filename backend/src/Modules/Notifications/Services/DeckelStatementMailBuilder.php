<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\Notifications\Contracts\MailContentBuilder;
use App\Modules\Notifications\Domain\StatementPeriod;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Enums\MailLanguage;
use App\Modules\Notifications\Mail\DeckelStatementMail;
use App\Shared\Mail\MailMessage;
use RuntimeException;

/**
 * Renders the Deckelauszug at send time (ADR-0039, ADR-0038 rule 5).
 *
 * A separate builder rather than an arm in {@see SettlementMailBuilder}, and a
 * new registration rather than a branch in the drain — that dispatch-by-kind
 * seam is the whole point of ADR-0038's {@see MailContentRegistry}, and this is
 * the first kind that exercises it against a *different subject*: the row's
 * `subject_id` is the member, not a settlement.
 *
 * The two fields it needs are both already on the row, which is what makes a
 * body-less queue work here at all:
 *
 * | `subject_id` | the member whose tab this is |
 * | `dedup_key`  | the period — and therefore the boundary the number is stated at |
 *
 * Neither is re-derived from today. A statement greylisted for three days is
 * rendered from the same period key on the fourth attempt as on the first, so
 * it says what it said on the 1st rather than shrinking as later settlements
 * claim the transactions it listed.
 */
class DeckelStatementMailBuilder implements MailContentBuilder
{
    public function __construct(
        private DeckelStatementService $statementService,
    ) {}

    public function supports(MailKind $kind): bool
    {
        return $kind === MailKind::DECKEL_STATEMENT;
    }

    /**
     * @param array<string,mixed> $outboxRow A row as `claimBatch()` returns it.
     *
     * @throws RuntimeException When the row's period key cannot be read. It was
     *         written by {@see \App\Modules\Notifications\Domain\StatementPeriod::key()},
     *         so this means the column was edited by hand — and a statement
     *         rendered at a guessed boundary would state a number for a date
     *         nobody asked about, which is worse than an unsent one.
     */
    public function build(array $outboxRow): MailMessage
    {
        $periodKey = trim((string) ($outboxRow['dedup_key'] ?? ''));
        $period = StatementPeriod::fromStoredKey($periodKey);

        if ($period === null) {
            throw new RuntimeException(sprintf(
                'Cannot render a Deckelauszug for period "%s": the queued row does not name a period this '
                . 'build can read, and a statement must not invent the date it states a tab at.',
                $periodKey,
            ));
        }

        return DeckelStatementMail::render($this->statementService->forMember(
            memberId: (string) $outboxRow['subject_id'],
            boundary: $period->boundary(),
            language: MailLanguage::fromPreferred((string) ($outboxRow['language'] ?? null)),
            // The snapshot, never `members.email` — the column is the proof of
            // who was written to, and the address may have changed or been
            // erased since (ADR-0038, ADR-0029).
            recipientAddress: (string) $outboxRow['recipient'],
        ));
    }
}
