<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Notifications\Services;

use App\Modules\Notifications\Enums\CronInterval;
use App\Modules\Notifications\Services\QueueHealth;
use App\Modules\Notifications\Services\RetrySchedule;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The stall predicates as pure functions of `(now, last_run_at, oldest_due_at,
 * interval)` — ADR-0039 decision 5, amending ADR-0038 rule 6.
 *
 * The regression that matters is {@see test_a_row_waiting_out_its_backoff_is_never_stalled}:
 * under ADR-0038's age-based definition, one stubborn mailbox on a long ladder
 * fired the alarm every time, which is the wolf-crying ADR-0038 itself forbids.
 * Being **due and ignored** replaces **being old**.
 */
class QueueHealthTest extends TestCase
{
    private const NOW = '2026-08-15 12:00:00';

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::NOW);
    }

    private function agoSeconds(int $seconds): DateTimeImmutable
    {
        return $this->now()->modify("-{$seconds} seconds");
    }

    // ------------------------------------------------------------------
    // Liveness — the scheduler stopped
    // ------------------------------------------------------------------

    public function test_a_recent_run_is_alive(): void
    {
        $this->assertFalse(QueueHealth::schedulerStopped(
            $this->agoSeconds(1800),
            $this->now(),
            CronInterval::HOURLY
        ));
    }

    public function test_one_missed_tick_is_not_yet_a_stopped_scheduler(): void
    {
        // A host under load skips a tick. The alarm has to survive that, or
        // nobody keeps it switched on.
        $this->assertFalse(QueueHealth::schedulerStopped(
            $this->agoSeconds(CronInterval::HOURLY->ticks(2)),
            $this->now(),
            CronInterval::HOURLY
        ));
    }

    public function test_silence_past_two_ticks_is_a_stopped_scheduler(): void
    {
        $this->assertTrue(QueueHealth::schedulerStopped(
            $this->agoSeconds(CronInterval::HOURLY->ticks(2) + 60),
            $this->now(),
            CronInterval::HOURLY
        ));
    }

    /**
     * The thresholds scale, which is the whole reason the interval is declared:
     * a gap that is a dead cron on an hourly install is an ordinary morning on
     * a daily one.
     */
    public function test_the_threshold_scales_with_the_declared_interval(): void
    {
        $threeHoursAgo = $this->agoSeconds(3 * 3600);

        $this->assertTrue(QueueHealth::schedulerStopped($threeHoursAgo, $this->now(), CronInterval::HOURLY));
        $this->assertFalse(QueueHealth::schedulerStopped($threeHoursAgo, $this->now(), CronInterval::DAILY));
    }

    public function test_an_installation_that_never_ran_is_not_reported_as_stopped(): void
    {
        // It has an owner already: the install gate refuses to finalize (#405),
        // and an external monitor that has never been pinged alarms by itself.
        $this->assertFalse(QueueHealth::schedulerStopped(null, $this->now(), CronInterval::HOURLY));
    }

    // ------------------------------------------------------------------
    // Throughput — something is due and nothing takes it
    // ------------------------------------------------------------------

    public function test_an_empty_queue_is_healthy(): void
    {
        $this->assertFalse(QueueHealth::queueStalled(null, $this->now(), CronInterval::HOURLY));
    }

    public function test_a_message_due_two_ticks_ago_is_not_yet_a_stall(): void
    {
        $this->assertFalse(QueueHealth::queueStalled(
            $this->agoSeconds(CronInterval::HOURLY->ticks(2)),
            $this->now(),
            CronInterval::HOURLY
        ));
    }

    public function test_a_message_due_three_ticks_ago_is_a_stall(): void
    {
        $this->assertTrue(QueueHealth::queueStalled(
            $this->agoSeconds(CronInterval::HOURLY->ticks(3)),
            $this->now(),
            CronInterval::HOURLY
        ));
    }

    /**
     * The regression this predicate exists for.
     *
     * A message queued days ago that has failed twice sits on a four-hour rung
     * with `next_attempt_at` in the future. Under the old age-based rule it
     * tripped the alarm; here it cannot, however long the ladder runs.
     */
    public function test_a_row_waiting_out_its_backoff_is_never_stalled(): void
    {
        $nextAttempt = $this->now()->modify(
            '+' . RetrySchedule::backoffSeconds(CronInterval::HOURLY, 3) . ' seconds'
        );

        $this->assertFalse(
            QueueHealth::queueStalled($nextAttempt, $this->now(), CronInterval::HOURLY),
            'a row whose next attempt is still in the future is not overdue, however old it is'
        );
    }

    // ------------------------------------------------------------------
    // Declared versus observed
    // ------------------------------------------------------------------

    public function test_two_runs_an_hour_apart_are_observed_as_hourly(): void
    {
        $this->assertSame(
            CronInterval::HOURLY,
            QueueHealth::observedInterval($this->agoSeconds(7200), $this->agoSeconds(3600))
        );
    }

    public function test_two_runs_a_day_apart_are_observed_as_daily(): void
    {
        $this->assertSame(
            CronInterval::DAILY,
            QueueHealth::observedInterval($this->agoSeconds(2 * 86400), $this->agoSeconds(86400))
        );
    }

    public function test_a_single_run_says_nothing_about_the_schedule(): void
    {
        $this->assertNull(QueueHealth::observedInterval(null, $this->now()));
    }

    public function test_a_manual_run_minutes_after_a_scheduled_one_says_nothing(): void
    {
        $this->assertNull(QueueHealth::observedInterval($this->agoSeconds(300), $this->now()));
    }

    public function test_declared_hourly_while_runs_arrive_daily_is_a_disagreement(): void
    {
        $this->assertTrue(QueueHealth::intervalDisagrees(CronInterval::HOURLY, CronInterval::DAILY));
    }

    /**
     * A cron that fires *more* often than declared makes every threshold
     * conservative — late, never wrong — so it is not worth a warning row.
     */
    public function test_a_scheduler_faster_than_declared_is_not_a_disagreement(): void
    {
        $this->assertFalse(QueueHealth::intervalDisagrees(CronInterval::DAILY, CronInterval::HOURLY));
        $this->assertFalse(QueueHealth::intervalDisagrees(CronInterval::HOURLY, CronInterval::HOURLY));
        $this->assertFalse(QueueHealth::intervalDisagrees(CronInterval::HOURLY, null));
    }
}
