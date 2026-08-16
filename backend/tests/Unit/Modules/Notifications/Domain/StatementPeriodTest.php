<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Notifications\Domain;

use App\Modules\Notifications\Domain\StatementPeriod;
use App\Modules\Notifications\Enums\StatementCadence;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The calendar anchor a Deckelauszug hangs off (ADR-0039, #462 step 11.3).
 *
 * No database, no clock, no sockets: every case states its own instant, which
 * is the property that makes the scheduled enqueue testable at all.
 */
class StatementPeriodTest extends TestCase
{
    // ── Keys ──────────────────────────────────────────────────────────

    public function test_a_monthly_period_is_keyed_by_its_month(): void
    {
        $this->assertSame('2026-08', $this->monthlyAt('2026-08-16 14:00:00')->key());
    }

    public function test_a_monthly_key_is_zero_padded_so_it_sorts(): void
    {
        // '2026-9' would sort after '2026-10' as a string, and this value goes
        // into a column an admin will one day want ordered.
        $this->assertSame('2026-09', $this->monthlyAt('2026-09-01 00:00:00')->key());
    }

    public function test_a_quarterly_period_covers_three_months_under_one_key(): void
    {
        foreach ([
            ['2026-07-01 00:00:00', '2026-Q3'],
            ['2026-08-16 14:00:00', '2026-Q3'],
            ['2026-09-30 23:59:59', '2026-Q3'],
            ['2026-10-01 00:00:00', '2026-Q4'],
            ['2026-01-01 00:00:00', '2026-Q1'],
        ] as [$instant, $expected]) {
            $this->assertSame($expected, $this->quarterlyAt($instant)->key(), $instant);
        }
    }

    // ── Boundaries ────────────────────────────────────────────────────

    /**
     * The August statement reports the tab as it stood when August began — the
     * „Stand: 1. August 2026" the mail prints (ADR-0039 decision 4).
     */
    public function test_the_boundary_is_the_first_instant_of_the_period(): void
    {
        $this->assertSame(
            '2026-08-01T00:00:00+00:00',
            $this->monthlyAt('2026-08-16 14:00:00')->boundary()->format('c')
        );
    }

    public function test_a_quarterly_boundary_is_the_first_instant_of_its_first_month(): void
    {
        $this->assertSame(
            '2026-07-01T00:00:00+00:00',
            $this->quarterlyAt('2026-09-30 23:59:59')->boundary()->format('c')
        );
    }

    /**
     * A month end is a period end and nothing more.
     *
     * The last second of February and the first of March belong to different
     * statements, and the leap day does not change which — the boundary is a
     * day number, not an offset from one.
     */
    public function test_month_ends_and_the_leap_day_land_where_the_calendar_says(): void
    {
        $this->assertSame('2028-02', $this->monthlyAt('2028-02-29 23:59:59')->key());
        $this->assertSame(
            '2028-02-01T00:00:00+00:00',
            $this->monthlyAt('2028-02-29 23:59:59')->boundary()->format('c')
        );
        $this->assertSame('2028-03', $this->monthlyAt('2028-03-01 00:00:00')->key());
    }

    /**
     * An instant is placed by its UTC value, not by the zone it arrives in.
     *
     * 1 August 00:30 in Berlin is still 31 July in UTC, which is the zone every
     * column in this system is in (`Shared\Time\Utc`). Placing it in August
     * would state a boundary the stored data does not agree with.
     */
    public function test_an_instant_is_placed_by_its_utc_value(): void
    {
        $berlin = new DateTimeImmutable('2026-08-01 00:30:00', new DateTimeZone('Europe/Berlin'));

        $this->assertSame('2026-07', StatementPeriod::containing(StatementCadence::MONTHLY, $berlin)->key());
    }

    // ── The catch-up cap ──────────────────────────────────────────────

    public function test_the_period_we_are_in_is_current(): void
    {
        $this->assertTrue(
            $this->monthlyAt('2026-08-01 00:00:00')->isCurrentAt(new DateTimeImmutable('2026-08-31 23:59:59'))
        );
    }

    /**
     * The reason the cap exists: a scheduler that was off for a year must not
     * mail twelve statements per member on the day it returns.
     */
    public function test_a_period_that_has_passed_is_no_longer_current(): void
    {
        $july = $this->monthlyAt('2026-07-15 12:00:00');

        $this->assertFalse($july->isCurrentAt(new DateTimeImmutable('2026-08-01 00:00:00')));
        $this->assertFalse($july->isCurrentAt(new DateTimeImmutable('2027-07-15 12:00:00')));
    }

    public function test_a_period_that_has_not_started_is_not_current_either(): void
    {
        $this->assertFalse(
            $this->monthlyAt('2026-09-01 00:00:00')->isCurrentAt(new DateTimeImmutable('2026-08-31 23:59:59'))
        );
    }

    // ── Parsing an explicit key ───────────────────────────────────────

    public function test_a_key_round_trips(): void
    {
        foreach ([[StatementCadence::MONTHLY, '2026-08'], [StatementCadence::QUARTERLY, '2026-Q3']] as [$cadence, $key]) {
            $this->assertSame($key, StatementPeriod::fromKey($cadence, $key)?->key());
        }
    }

    public function test_a_parsed_key_carries_the_same_boundary_as_a_derived_one(): void
    {
        $this->assertEquals(
            $this->monthlyAt('2026-08-16 14:00:00')->boundary(),
            StatementPeriod::fromKey(StatementCadence::MONTHLY, '2026-08')?->boundary()
        );
    }

    /**
     * `--period` typos are reported, not normalised.
     *
     * A key of the wrong shape for the configured cadence is in this list on
     * purpose: `2026-Q3` on a monthly installation is a mistake worth naming at
     * the command line, and silently reading it as a month would enqueue under
     * a dedup key nothing else will ever produce.
     */
    public function test_an_unparseable_key_is_refused_rather_than_guessed(): void
    {
        foreach (['', '2026', '2026-13', '2026-00', '26-08', '2026-8', 'august', '2026-Q3'] as $key) {
            $this->assertNull(StatementPeriod::fromKey(StatementCadence::MONTHLY, $key), $key);
        }

        foreach (['2026-Q0', '2026-Q5', '2026-08'] as $key) {
            $this->assertNull(StatementPeriod::fromKey(StatementCadence::QUARTERLY, $key), $key);
        }
    }

    // ── off ───────────────────────────────────────────────────────────

    public function test_a_disabled_cadence_has_no_current_period(): void
    {
        $this->expectException(InvalidArgumentException::class);

        StatementPeriod::containing(StatementCadence::OFF, new DateTimeImmutable('2026-08-16 14:00:00'));
    }

    public function test_a_disabled_cadence_parses_no_key(): void
    {
        $this->assertNull(StatementPeriod::fromKey(StatementCadence::OFF, '2026-08'));
    }

    // ── The cadence enum itself ───────────────────────────────────────

    public function test_an_unreadable_stored_cadence_falls_back_to_off(): void
    {
        // Not to the default. A column nobody could parse is not a reason to
        // mail an entire membership.
        foreach ([null, '', 'weekly', 'yearly', 42] as $stored) {
            $this->assertSame(StatementCadence::OFF, StatementCadence::fromDeclared($stored));
        }
    }

    public function test_a_stored_cadence_is_read_back(): void
    {
        $this->assertSame(StatementCadence::MONTHLY, StatementCadence::fromDeclared('monthly'));
        $this->assertSame(StatementCadence::QUARTERLY, StatementCadence::fromDeclared('quarterly'));
    }

    public function test_weekly_is_not_a_cadence(): void
    {
        $this->assertNotContains('weekly', array_column(StatementCadence::cases(), 'value'));
    }

    /**
     * The send direction (#463): a queued row's key is read back without being
     * told the cadence, because the club may have changed it since.
     *
     * This is the case that makes the method necessary rather than convenient.
     * A statement queued as `2026-08` on a monthly installation must still
     * render after somebody switches the club to quarterly — asking the current
     * cadence to parse it would fail, permanently, and leave a member's
     * statement stuck in the queue over a settings change made after it was
     * written.
     */
    public function test_a_stored_key_is_read_back_without_knowing_the_cadence(): void
    {
        $monthly = StatementPeriod::fromStoredKey('2026-08');
        $this->assertNotNull($monthly);
        $this->assertSame('2026-08', $monthly->key());
        $this->assertSame('2026-08-01', $monthly->boundary()->format('Y-m-d'));

        $quarterly = StatementPeriod::fromStoredKey('2026-Q3');
        $this->assertNotNull($quarterly);
        $this->assertSame('2026-Q3', $quarterly->key());
        $this->assertSame('2026-07-01', $quarterly->boundary()->format('Y-m-d'));
    }

    /**
     * Anything else is null rather than a guess. The renderer refuses to send
     * at that point, which is the right answer: a statement dated at an
     * invented boundary states a number for a moment nobody asked about.
     */
    public function test_an_unreadable_stored_key_is_refused(): void
    {
        foreach (['', '2026', '2026-13', '2026-Q5', 'August', '2026-8'] as $key) {
            $this->assertNull(StatementPeriod::fromStoredKey($key), "\"{$key}\" is not a period");
        }
    }

    // ── helpers ───────────────────────────────────────────────────────

    private function monthlyAt(string $instant): StatementPeriod
    {
        return StatementPeriod::containing(
            StatementCadence::MONTHLY,
            new DateTimeImmutable($instant, new DateTimeZone('UTC')),
        );
    }

    private function quarterlyAt(string $instant): StatementPeriod
    {
        return StatementPeriod::containing(
            StatementCadence::QUARTERLY,
            new DateTimeImmutable($instant, new DateTimeZone('UTC')),
        );
    }
}
