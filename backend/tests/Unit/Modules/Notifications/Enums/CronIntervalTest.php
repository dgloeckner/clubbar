<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Notifications\Enums;

use App\Modules\Notifications\Enums\CronInterval;
use PHPUnit\Framework\TestCase;

/**
 * The declared scheduler granularity as a table of `value → seconds`
 * (ADR-0039 decision 5, extended by #473).
 *
 * Every threshold in the mail feature is `interval × ticks`, so this enum is
 * the one place where a wrong number would be wrong everywhere at once — and
 * the ladder and both stall windows are tested against *these* values rather
 * than against literals of their own.
 */
class CronIntervalTest extends TestCase
{
    public function test_each_case_counts_the_seconds_its_name_claims(): void
    {
        $this->assertSame(900, CronInterval::FIFTEEN_MINUTES->seconds());
        $this->assertSame(3600, CronInterval::HOURLY->seconds());
        $this->assertSame(86400, CronInterval::DAILY->seconds());
    }

    /**
     * The cases are ordered fastest-first, and every one is strictly faster
     * than the next. `intervalDisagrees()` compares them by `seconds()`, so an
     * accidental duplicate or an out-of-order case would silently stop a
     * mismatch being reportable.
     */
    public function test_the_cases_are_strictly_ordered_fastest_first(): void
    {
        $seconds = array_map(fn(CronInterval $i) => $i->seconds(), CronInterval::cases());
        $sorted = $seconds;
        sort($sorted);

        $this->assertSame($sorted, $seconds);
        $this->assertSame(count($seconds), count(array_unique($seconds)));
    }

    public function test_the_fifteen_minute_cadence_round_trips_as_a_declared_value(): void
    {
        $this->assertSame(
            CronInterval::FIFTEEN_MINUTES,
            CronInterval::fromDeclared('fifteen_minutes')
        );
    }

    /**
     * Deliberately still `hourly` after #473 added a faster case.
     *
     * A missing or unreadable declaration must not be guessed *faster* than
     * reality: that would set the liveness alarm to half an hour on an
     * installation that genuinely runs hourly, and an alarm firing every hour
     * on a healthy host is the wolf-crying ADR-0038 forbids.
     */
    public function test_an_undeclared_installation_is_treated_as_hourly(): void
    {
        $this->assertSame(CronInterval::HOURLY, CronInterval::DEFAULT);
        $this->assertSame(CronInterval::HOURLY, CronInterval::fromDeclared(null));
        $this->assertSame(CronInterval::HOURLY, CronInterval::fromDeclared('every_other_tuesday'));
    }

    /**
     * Adding a faster case does not touch the argument against the slower one:
     * `weekly` is refused because the § 7 Abs. 3 announcement distance collapses
     * at that granularity, and it is a constant so the API's refusal and this
     * enum cannot drift into disagreeing about which word it is.
     */
    public function test_weekly_is_still_refused_rather_than_unsupported(): void
    {
        $this->assertSame('weekly', CronInterval::REFUSED);
        $this->assertNull(CronInterval::tryFrom(CronInterval::REFUSED));
    }

    public function test_an_observed_gap_is_classified_as_the_interval_it_comfortably_exceeds(): void
    {
        $this->assertNull(CronInterval::fromObservedGap(600), 'ten minutes concludes nothing');
        $this->assertSame(CronInterval::FIFTEEN_MINUTES, CronInterval::fromObservedGap(900));
        $this->assertSame(CronInterval::FIFTEEN_MINUTES, CronInterval::fromObservedGap(2400));
        $this->assertSame(CronInterval::HOURLY, CronInterval::fromObservedGap(3600));
        $this->assertSame(CronInterval::DAILY, CronInterval::fromObservedGap(86400));
    }

    /**
     * Generous on purpose: a cron whose ticks arrive a few minutes late is
     * still the cadence it was configured as, in every case.
     */
    public function test_a_late_tick_is_still_the_interval_it_was_configured_as(): void
    {
        foreach (CronInterval::cases() as $interval) {
            $late = (int) ($interval->seconds() * 0.9);

            $this->assertSame(
                $interval,
                CronInterval::fromObservedGap($late),
                "{$interval->value} arriving ten percent early should still read as itself"
            );
        }
    }

    public function test_ticks_multiplies_and_never_returns_a_negative_wait(): void
    {
        $this->assertSame(2700, CronInterval::FIFTEEN_MINUTES->ticks(3));
        $this->assertSame(0, CronInterval::FIFTEEN_MINUTES->ticks(-1));
    }
}
