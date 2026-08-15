<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\Notifications\Enums\MailKind;

/**
 * How long a delivered message stays in the queue (#408, ADR-0029).
 *
 * The outbox snapshots the recipient address, and that snapshot is
 * **operational tier**: it is contact data, not a Beleg. Once a message has
 * been delivered the address has no further job — the durable proof that the
 * announcement was made is the `settlement_announcements` row the drain writes
 * beside it, which names no address and is kept for the retention period.
 *
 * Two rules, and the second is the one worth stating out loud:
 *
 * 1. **Only `sent` rows are pruned.** `pending`, `failed` and `superseded` rows
 *    are never touched at any age. Pruning must not become a way to lose a
 *    message that never went out — a `failed` row *is* the record that somebody
 *    was not reached, and ADR-0038 rule 6 makes that the club's to see. A queue
 *    that quietly emptied its own failures would be worse than one that never
 *    reported them.
 * 2. **The window is keyed on the kind.** Today every kind answers the same
 *    number, and that is a fact about the kinds that exist rather than a
 *    simplification: each of them either leaves a durable settlement-side
 *    record behind (the money mail) or is an operational warning addressed to
 *    an admin, and neither has a reason to hold an address longer than the
 *    other. #462's `deckel_statement` is the first kind that will disagree —
 *    twelve rows per member per year proving nothing anyone will ever need —
 *    and it lands as one arm of the match below rather than as a second policy.
 *
 * Pure and clock-free, like {@see RetrySchedule}: the caller supplies the date
 * arithmetic, so the tests can state the whole table without a `sleep`.
 */
final class MailRetention
{
    /**
     * Days a `sent` row is kept after delivery.
     *
     * Ninety, because the questions a treasurer asks about a delivered
     * announcement — *"which address did it go to?"*, *"did it bounce?"* — are
     * asked in the weeks around the collection, not the years after it. § 7
     * Abs. 3's own complaint window is six weeks (Nutzungsordnung § 4 Abs. 6),
     * so ninety days outlasts the period in which anybody has standing to
     * dispute the announcement, with a month to spare.
     */
    public const DEFAULT_SENT_DAYS = 90;

    /**
     * The most rows one prune pass may delete.
     *
     * Pruning runs at the tail of a drain, and a drain has a wall-clock budget
     * it is not allowed to blow (see {@see DrainService}). The first prune on an
     * installation upgrading with years of queue behind it would otherwise be a
     * single `DELETE` of unknown size at the end of a run the host is already
     * timing. Bounded, it takes several ticks to catch up and no tick is at
     * risk — which is what a queue is for.
     */
    public const PRUNE_BATCH = 500;

    /** @return int Days a delivered message of this kind is kept. */
    public static function sentDaysFor(MailKind $kind): int
    {
        return match ($kind) {
            MailKind::SEPA_PRENOTIFICATION,
            MailKind::CANCELLATION_NOTICE,
            MailKind::PAYMENT_REQUEST,
            MailKind::KEY_EXPIRY_WARNING,
            MailKind::TERMINAL_TOKEN_EXPIRY_WARNING,
            MailKind::TERMINAL_ANOMALY_WARNING,
            // The security notice to a former address (#469) keeps the default
            // for the same reason the operational warnings do: what it holds is
            // an address, and the durable record of the change it announces is
            // the `email_changed` audit entry, which names both addresses and
            // is not touched here. Ninety days is well past the point at which
            // an admin would still be asking whether they were told.
            MailKind::ADMIN_EMAIL_CHANGED => self::DEFAULT_SENT_DAYS,
        };
    }

    /**
     * The cutoff a kind's `sent_at` must be older than to be pruned.
     *
     * @param int $now Unix timestamp, injected so a test can state an age
     *                 instead of waiting for one.
     * @return string `Y-m-d H:i:s`
     */
    public static function cutoffFor(MailKind $kind, int $now): string
    {
        return date('Y-m-d H:i:s', $now - self::sentDaysFor($kind) * 86400);
    }
}
