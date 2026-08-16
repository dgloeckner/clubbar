<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Notifications\Services;

use App\Modules\Notifications\Enums\CronInterval;
use App\Modules\Notifications\Services\RetrySchedule;
use PHPUnit\Framework\TestCase;

/**
 * The retry ladder as a table of `(interval, attempt) → seconds`
 * (ADR-0039 decision 5).
 *
 * No clock and no database: the ladder is a pure function, and the property
 * worth pinning is that it is measured in *ticks of this installation's
 * scheduler* rather than in wall-clock minutes. A fifteen-minute backoff on an
 * hourly cron is not a fifteen-minute backoff, and the old flat constant made
 * that mistake in a way nothing failed on.
 */
class RetryScheduleTest extends TestCase
{
    /**
     * The cadence the setup instructions have recommended since #403, and the
     * one where the tick-relative ladder and the flat fifteen-minute constant it
     * replaced finally agree — the first rung *is* greylisting's fifteen
     * minutes, and it is that only because the cron is awake to act on it
     * (#473).
     */
    public function test_fifteen_minute_ladder_is_fifteen_thirty_and_sixty_minutes(): void
    {
        $this->assertSame(900, RetrySchedule::backoffSeconds(CronInterval::FIFTEEN_MINUTES, 1));
        $this->assertSame(1800, RetrySchedule::backoffSeconds(CronInterval::FIFTEEN_MINUTES, 2));
        $this->assertSame(3600, RetrySchedule::backoffSeconds(CronInterval::FIFTEEN_MINUTES, 3));
    }

    /**
     * The whole ladder fits inside the coarser case's *first* rung, which is
     * the point of declaring the faster cadence at all: an installation that
     * had to say `hourly` spent an hour reaching an attempt this one has
     * already made four times.
     */
    public function test_the_fastest_ladder_is_shorter_than_one_hourly_rung(): void
    {
        $ladder = 0;
        for ($attempt = 1; $attempt < RetrySchedule::MAX_ATTEMPTS; $attempt++) {
            $ladder += RetrySchedule::backoffSeconds(CronInterval::FIFTEEN_MINUTES, $attempt);
        }

        $this->assertSame(6300, $ladder);
        $this->assertLessThan(
            RetrySchedule::backoffSeconds(CronInterval::HOURLY, 3),
            $ladder,
            'the fifteen-minute ladder should finish well inside a single hourly backoff'
        );
    }

    public function test_hourly_ladder_is_one_two_and_four_hours(): void
    {
        $this->assertSame(3600, RetrySchedule::backoffSeconds(CronInterval::HOURLY, 1));
        $this->assertSame(7200, RetrySchedule::backoffSeconds(CronInterval::HOURLY, 2));
        $this->assertSame(14400, RetrySchedule::backoffSeconds(CronInterval::HOURLY, 3));
    }

    public function test_daily_ladder_is_one_two_and_four_days(): void
    {
        $this->assertSame(86400, RetrySchedule::backoffSeconds(CronInterval::DAILY, 1));
        $this->assertSame(172800, RetrySchedule::backoffSeconds(CronInterval::DAILY, 2));
        $this->assertSame(345600, RetrySchedule::backoffSeconds(CronInterval::DAILY, 3));
    }

    /**
     * The whole point of the change: every wait is a whole number of ticks, so
     * the drain is awake for each of them.
     */
    public function test_every_rung_is_a_whole_number_of_ticks(): void
    {
        foreach (CronInterval::cases() as $interval) {
            for ($attempt = 1; $attempt < RetrySchedule::MAX_ATTEMPTS; $attempt++) {
                $seconds = RetrySchedule::backoffSeconds($interval, $attempt);

                $this->assertSame(
                    0,
                    $seconds % $interval->seconds(),
                    "attempt {$attempt} on {$interval->value} does not land on a tick boundary"
                );
            }
        }
    }

    public function test_the_fourth_attempt_ends_the_ladder(): void
    {
        $this->assertTrue(RetrySchedule::shouldRetry(transient: true, attempt: 1));
        $this->assertTrue(RetrySchedule::shouldRetry(transient: true, attempt: 3));
        $this->assertFalse(
            RetrySchedule::shouldRetry(transient: true, attempt: RetrySchedule::MAX_ATTEMPTS),
            'attempt 4 is the last one, so nothing schedules a fifth'
        );
    }

    /**
     * A rejected address does not become a good address by waiting.
     */
    public function test_a_permanent_failure_is_never_retried(): void
    {
        $this->assertFalse(RetrySchedule::shouldRetry(transient: false, attempt: 1));
    }

    /**
     * Defensive rather than reachable: if an attempt count ever runs past the
     * ladder, the wait must be the longest rung and not zero — a bug that
     * hammers an MTA is worse than one that is slow.
     */
    public function test_an_attempt_past_the_ladder_waits_the_longest_rung(): void
    {
        $this->assertSame(
            RetrySchedule::backoffSeconds(CronInterval::HOURLY, 3),
            RetrySchedule::backoffSeconds(CronInterval::HOURLY, 99)
        );
        $this->assertSame(
            RetrySchedule::backoffSeconds(CronInterval::HOURLY, 1),
            RetrySchedule::backoffSeconds(CronInterval::HOURLY, 0)
        );
    }

    public function test_the_multiplier_table_has_one_rung_per_wait(): void
    {
        $this->assertCount(
            RetrySchedule::MAX_ATTEMPTS - 1,
            RetrySchedule::TICK_MULTIPLIERS,
            'there is no wait after the last attempt, so there is one fewer multiplier than attempts'
        );
    }
}
