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
 * 2. **The window is keyed on the kind.** Every kind still answers the same
 *    number, and that is a fact about the kinds that exist rather than a
 *    simplification: each of them either leaves a durable settlement-side
 *    record behind (the money mail) or is an operational warning addressed to
 *    an admin, and neither has a reason to hold an address longer than the
 *    other. `deckel_statement` (ADR-0039 decision 6, #462) is the first kind
 *    that holds its address for a *different* reason — twelve rows per member
 *    per year proving nothing anyone will ever need — so it names its own
 *    constant and takes its own arm of the match below, free to move without
 *    dragging the announcements with it.
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
     * Days a delivered Deckelauszug is kept (ADR-0039 decision 6).
     *
     * The same number as {@see DEFAULT_SENT_DAYS} today, and a separate constant
     * anyway, because it is held for an entirely different reason and the two
     * are free to move apart. An announcement is kept because somebody may ask
     * which address it went to while the § 7 Abs. 3 complaint window is open. A
     * statement has no such window, no promise behind it and no durable
     * settlement-side record beside it: twelve rows per member per year, each
     * carrying a snapshot address, proving nothing anyone will ever need. If
     * either number is ever revisited it will be this one, downwards.
     */
    public const STATEMENT_SENT_DAYS = 90;

    /**
     * Days a delivered near-limit digest is kept (ADR-0047, migration 054).
     *
     * The shortest window in this class, and the one kind where that is easy to
     * argue. A digest proves nothing, announces nothing and is not the record
     * of anything: the condition it reports is *live*, still on the dashboard,
     * and the numbers in the delivered copy are stale within a day of being
     * sent. What the row holds past delivery is one admin's address and a
     * window key.
     *
     * Thirty days is well past the point where a recipient is still asking
     * whether last week's digest went out, and — on a daily cadence with
     * several recipients — it is the difference between a few dozen rows and a
     * few thousand sitting in the queue proving nothing.
     */
    public const DIGEST_SENT_DAYS = 30;

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
            MailKind::KEY_EXPIRY_WARNING,
            MailKind::TERMINAL_TOKEN_EXPIRY_WARNING,
            MailKind::TERMINAL_ANOMALY_WARNING,
            // The issuance notice (ADR-0043) keeps the default for the same
            // reason: what the row holds past delivery is an address, and the
            // durable record of the minting it announces is the
            // `terminal_token_created` / `_rotated` audit entry, which this
            // never touches. Ninety days outlasts any window in which an admin
            // is still asking whether they were told about a credential.
            MailKind::TERMINAL_TOKEN_ISSUED,
            // The security notice to a former address (#469) keeps the default
            // for the same reason the operational warnings do: what it holds is
            // an address, and the durable record of the change it announces is
            // the `email_changed` audit entry, which names both addresses and
            // is not touched here. Ninety days is well past the point at which
            // an admin would still be asking whether they were told.
            MailKind::ADMIN_EMAIL_CHANGED,
            // Key lifecycle notices (ADR-0036) keep the default for the same
            // reason: the durable record is the `key_registered` /
            // `key_activated` / `key_revoked` audit entry, which carries the
            // fingerprint and is untouched here. This copy exists to reach an
            // admin who was not the one acting, and ninety days is well past
            // the point at which they would still be asking whether they were
            // told.
            MailKind::ENCRYPTION_KEY_REGISTERED,
            MailKind::ENCRYPTION_KEY_ACTIVATED,
            MailKind::ENCRYPTION_KEY_REVOKED,
            // Admin lifecycle notices (ADR-0044) keep the default on the same
            // reasoning: the durable record is the `create` / `role_granted` /
            // `role_revoked` audit entry, which names actor, target and the
            // roles that moved and is untouched here. This copy exists to reach
            // the admins who were *not* acting, and the club address, within
            // the window in which either would still be asking whether they
            // were told.
            MailKind::ADMIN_ACCOUNT_CREATED,
            MailKind::ADMIN_ROLE_CHANGED,
            // The Jugendschutz notice (#622) keeps the default, and the reason
            // is worth stating because the instinct is to keep it for ten years
            // beside the incident. It is not the incident. The durable record
            // is the `jugendschutz_violation` audit entry, retained with the
            // rest of the log and untouched here; this row is the *telling*,
            // and once a human has been told, the copy holding their address
            // has done its work. Keeping it longer would retain an address
            // against a youth-protection matter for no added evidentiary value.
            MailKind::JUGENDSCHUTZ_VIOLATION,
            // The backup secret warning (#691) keeps the default, and here the
            // reasoning runs the other way from the kinds above: there is no
            // audit entry behind it to be the durable record, because ADR-0049
            // decision 8 keeps the backup out of the application's schema
            // entirely. What it holds is still only an address and a date, and
            // the durable record of *whether the secret was rotated* is the
            // secret working — visible every night in the run's own output and
            // in the journal beside the archives. Ninety days is well past the
            // last tier, so a club is never pruning a warning it has not yet
            // had the chance to act on.
            MailKind::BACKUP_SECRET_EXPIRY_WARNING => self::DEFAULT_SENT_DAYS,
            MailKind::DECKEL_STATEMENT => self::STATEMENT_SENT_DAYS,
            MailKind::CREDIT_LIMIT_DIGEST => self::DIGEST_SENT_DAYS,
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
