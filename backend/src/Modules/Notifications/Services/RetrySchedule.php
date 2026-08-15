<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\Notifications\Enums\CronInterval;

/**
 * When a failed message gets its next go (ADR-0039 decision 5).
 *
 * This replaces a flat `RETRY_BACKOFF_SECONDS = 900`, which was tuned for
 * greylisting's ~15-minute expectation and is already dead letter at an hourly
 * cron: the drain cannot act between ticks, so a fifteen-minute `next_attempt_at`
 * on an hourly scheduler simply means "the next tick", and three of them mean
 * "the next three ticks" — a three-hour ladder wearing a forty-five-minute
 * label. On a daily cron it is worse: the whole ladder is spent inside three
 * days and the message is `failed` before greylisting has had a second look.
 *
 * So the ladder counts in **ticks**, and the multipliers are the ordinary
 * exponential ones:
 *
 * | Attempt | Multiplier | Hourly | Daily |
 * |---|---|---|---|
 * | 1 → 2 | 1× | 1 h | 1 d |
 * | 2 → 3 | 2× | 2 h | 2 d |
 * | 3 → 4 | 4× | 4 h | 4 d |
 * | 4 | — | `failed` | `failed` |
 *
 * Hourly gives roughly seven hours of trying before the fourth attempt and
 * fifteen if the last wait is counted — far more than greylisting needs, and
 * nothing at all against a seven-day announcement window. Daily degrades to a
 * ladder the cron is actually awake for instead of four attempts crammed into
 * a schedule that fires once.
 *
 * Applies to **every** {@see \App\Modules\Notifications\Enums\MailKind}, the
 * Vorabankündigung included. ADR-0039 records this as a deliberate change to
 * shipped behaviour rather than an addition beside it: there is one drain, and
 * a second ladder would be a second thing to reason about at three in the
 * morning.
 *
 * Pure and clock-free on purpose — every value here is a function of
 * `(interval, attempt)` and nothing else, which is what lets the tests state
 * the whole table without a database or a `sleep`.
 */
final class RetrySchedule
{
    /**
     * Attempts before a message is given up on.
     *
     * Four rather than ADR-0038's three, because the fourth costs nothing on an
     * hourly cron and buys the daily one a ladder that reaches past the second
     * day.
     */
    public const MAX_ATTEMPTS = 4;

    /**
     * Ticks to wait after attempt *n*, indexed from the first failure.
     *
     * One shorter than MAX_ATTEMPTS by construction: there is no wait after the
     * last attempt, because there is no attempt after it.
     *
     * @var list<int>
     */
    public const TICK_MULTIPLIERS = [1, 2, 4];

    /**
     * How long the message waits after its $attempt-th failure.
     *
     * $attempt is the count *including* the failure that just happened, which is
     * how `mail_outbox.attempts` reads after the update — attempt 1 is the first
     * failure. A value past the ladder returns the last rung rather than zero:
     * an unexpected extra attempt should wait longest, not hammer.
     */
    public static function backoffSeconds(CronInterval $interval, int $attempt): int
    {
        $index = max(0, min($attempt - 1, count(self::TICK_MULTIPLIERS) - 1));

        return $interval->ticks(self::TICK_MULTIPLIERS[$index]);
    }

    /**
     * Is there another go after this failure?
     *
     * Only for a transient one. A rejected address does not become a good
     * address by waiting, and re-offering it four times only teaches the
     * receiving MTA that this host retries garbage.
     */
    public static function shouldRetry(bool $transient, int $attempt): bool
    {
        return $transient && $attempt < self::MAX_ATTEMPTS;
    }
}
