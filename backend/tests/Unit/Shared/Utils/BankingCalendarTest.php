<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Utils;

use App\Shared\Utils\BankingCalendar;
use PHPUnit\Framework\TestCase;

class BankingCalendarTest extends TestCase
{
    // ── Easter computation ────────────────────────────────────────────

    public function test_easter_sunday_matches_known_dates(): void
    {
        $this->assertSame('2026-04-05', BankingCalendar::easterSunday(2026));
        $this->assertSame('2027-03-28', BankingCalendar::easterSunday(2027));
        $this->assertSame('2028-04-16', BankingCalendar::easterSunday(2028));
    }

    public function test_easter_sunday_matches_historic_and_far_future_dates(): void
    {
        // Reference values for the Anonymous Gregorian algorithm.
        $this->assertSame('2000-04-23', BankingCalendar::easterSunday(2000));
        $this->assertSame('2024-03-31', BankingCalendar::easterSunday(2024));
        $this->assertSame('2038-04-25', BankingCalendar::easterSunday(2038));
        $this->assertSame('2100-03-28', BankingCalendar::easterSunday(2100));
    }

    public function test_easter_sunday_always_falls_on_a_sunday(): void
    {
        for ($year = 2020; $year <= 2060; $year++) {
            $date = BankingCalendar::easterSunday($year);
            $this->assertSame(
                '7',
                (new \DateTimeImmutable($date))->format('N'),
                "Easter {$year} ({$date}) must be a Sunday"
            );
        }
    }

    // ── TARGET2 holiday set ───────────────────────────────────────────

    public function test_target2_holidays_contains_the_six_closing_days(): void
    {
        $this->assertSame([
            '2026-01-01', // New Year's Day
            '2026-04-03', // Good Friday
            '2026-04-06', // Easter Monday
            '2026-05-01', // Labour Day
            '2026-12-25', // Christmas Day
            '2026-12-26', // Christmas Holiday
        ], BankingCalendar::target2Holidays(2026));
    }

    public function test_target2_holidays_tracks_the_moving_easter_dates(): void
    {
        $holidays2027 = BankingCalendar::target2Holidays(2027);

        $this->assertContains('2027-03-26', $holidays2027); // Good Friday
        $this->assertContains('2027-03-29', $holidays2027); // Easter Monday
        $this->assertNotContains('2027-04-03', $holidays2027);
    }

    // ── isBusinessDay ─────────────────────────────────────────────────

    public function test_ordinary_weekdays_are_business_days(): void
    {
        $this->assertTrue(BankingCalendar::isBusinessDay('2026-08-03')); // Monday
        $this->assertTrue(BankingCalendar::isBusinessDay('2026-08-07')); // Friday
    }

    public function test_weekends_are_not_business_days(): void
    {
        $this->assertFalse(BankingCalendar::isBusinessDay('2026-08-08')); // Saturday
        $this->assertFalse(BankingCalendar::isBusinessDay('2026-08-09')); // Sunday — the issue #11 date
    }

    public function test_target2_closing_days_are_not_business_days(): void
    {
        $this->assertFalse(BankingCalendar::isBusinessDay('2026-01-01')); // Thursday
        $this->assertFalse(BankingCalendar::isBusinessDay('2026-04-03')); // Good Friday
        $this->assertFalse(BankingCalendar::isBusinessDay('2026-04-06')); // Easter Monday
        $this->assertFalse(BankingCalendar::isBusinessDay('2026-05-01')); // Friday
        $this->assertFalse(BankingCalendar::isBusinessDay('2026-12-25')); // Friday
        $this->assertFalse(BankingCalendar::isBusinessDay('2028-12-26')); // Tuesday
    }

    public function test_day_adjacent_to_a_holiday_is_a_business_day(): void
    {
        $this->assertTrue(BankingCalendar::isBusinessDay('2026-04-02')); // Maundy Thursday
        $this->assertTrue(BankingCalendar::isBusinessDay('2026-04-07')); // Easter Tuesday
        $this->assertTrue(BankingCalendar::isBusinessDay('2028-12-27')); // Wednesday after Christmas
    }

    // ── nextBusinessDay ───────────────────────────────────────────────

    public function test_next_business_day_returns_input_when_already_valid(): void
    {
        $this->assertSame('2026-08-03', BankingCalendar::nextBusinessDay('2026-08-03'));
        $this->assertSame('2026-04-07', BankingCalendar::nextBusinessDay('2026-04-07'));
    }

    public function test_next_business_day_rolls_weekend_to_monday(): void
    {
        $this->assertSame('2026-08-10', BankingCalendar::nextBusinessDay('2026-08-08')); // Sat
        $this->assertSame('2026-08-10', BankingCalendar::nextBusinessDay('2026-08-09')); // Sun
    }

    public function test_next_business_day_rolls_across_easter_weekend(): void
    {
        // Good Friday → Sat → Sun → Easter Monday → Tuesday: four consecutive closing days.
        $this->assertSame('2026-04-07', BankingCalendar::nextBusinessDay('2026-04-03'));
    }

    public function test_next_business_day_rolls_across_consecutive_holidays(): void
    {
        // 2028-12-25 (Mon) and 2028-12-26 (Tue) are both closing days.
        $this->assertSame('2028-12-27', BankingCalendar::nextBusinessDay('2028-12-25'));
    }

    public function test_next_business_day_rolls_across_holiday_then_weekend(): void
    {
        // 2026-12-25 is a Friday, followed by Sat/Sun.
        $this->assertSame('2026-12-28', BankingCalendar::nextBusinessDay('2026-12-25'));
        // 2027-01-01 is a Friday, followed by Sat/Sun.
        $this->assertSame('2027-01-04', BankingCalendar::nextBusinessDay('2027-01-01'));
    }

    public function test_next_business_day_crosses_the_year_boundary(): void
    {
        // 2026-12-31 (Thu) is a business day; the roll from 2027-01-01 must
        // consult the 2027 holiday set, not the 2026 one.
        $this->assertSame('2026-12-31', BankingCalendar::nextBusinessDay('2026-12-31'));
        $this->assertSame('2027-01-04', BankingCalendar::nextBusinessDay('2027-01-02'));
    }

    public function test_next_business_day_is_idempotent(): void
    {
        $once = BankingCalendar::nextBusinessDay('2026-04-03');
        $this->assertSame($once, BankingCalendar::nextBusinessDay($once));
    }

    public function test_holiday_falling_on_a_weekend_is_not_double_counted(): void
    {
        // 2027-05-01 is a Saturday. The next business day is the Monday,
        // not shifted further by the holiday landing on a weekend.
        $this->assertFalse(BankingCalendar::isBusinessDay('2027-05-01'));
        $this->assertSame('2027-05-03', BankingCalendar::nextBusinessDay('2027-05-01'));
    }

    // ── Input handling ────────────────────────────────────────────────

    public function test_accepts_dates_with_a_time_component(): void
    {
        $this->assertFalse(BankingCalendar::isBusinessDay('2026-08-09 14:30:00'));
        $this->assertSame('2026-08-10', BankingCalendar::nextBusinessDay('2026-08-09 14:30:00'));
    }

    public function test_rejects_an_unparseable_date(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BankingCalendar::isBusinessDay('not-a-date');
    }
}
