<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Notifications\Domain;

use App\Modules\Notifications\Domain\DigestWindow;
use App\Modules\Notifications\Enums\DigestCadence;
use App\Shared\Time\ClubTimeZone;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * The near-limit digest's idempotency, which is entirely this class.
 *
 * Nothing in the feature remembers what it has sent. The scan names the window
 * it is inside, and `UNIQUE (kind, subject_id, dedup_key)` refuses every later
 * attempt at the same one. So a wrong window key is not a cosmetic bug: two
 * ticks that disagree send two digests, and two weeks that agree send one.
 */
class DigestWindowTest extends TestCase
{
    private const BERLIN = 'Europe/Berlin';

    protected function setUp(): void
    {
        parent::setUp();
        // Pinned rather than assumed: the keys are cut in the club's zone, and
        // a runner in UTC would otherwise silently pass the tests that matter
        // least and skip the ones that matter most.
        putenv(ClubTimeZone::ENV_KEY . '=' . self::BERLIN);
        $_ENV[ClubTimeZone::ENV_KEY] = self::BERLIN;
    }

    protected function tearDown(): void
    {
        putenv(ClubTimeZone::ENV_KEY);
        unset($_ENV[ClubTimeZone::ENV_KEY]);
        parent::tearDown();
    }

    public function test_an_off_cadence_has_no_window(): void
    {
        $this->assertNull(DigestWindow::containing(DigestCadence::OFF, $this->utc('2026-08-24 09:00:00')));
    }

    public function test_a_daily_window_is_the_clubs_calendar_day(): void
    {
        $window = DigestWindow::containing(DigestCadence::DAILY, $this->utc('2026-08-24 09:00:00'));

        $this->assertNotNull($window);
        $this->assertSame('2026-08-24', $window->key);
        $this->assertSame(DigestCadence::DAILY, $window->cadence);
    }

    public function test_a_weekly_window_is_the_iso_week(): void
    {
        $window = DigestWindow::containing(DigestCadence::WEEKLY, $this->utc('2026-08-24 09:00:00'));

        $this->assertNotNull($window);
        $this->assertSame('2026-W35', $window->key);
    }

    public function test_a_monthly_window_is_the_calendar_month(): void
    {
        $window = DigestWindow::containing(DigestCadence::MONTHLY, $this->utc('2026-08-24 09:00:00'));

        $this->assertNotNull($window);
        $this->assertSame('2026-08', $window->key);
    }

    /**
     * Every tick inside one week names the same window — which is what makes a
     * scheduler running every fifteen minutes send one digest rather than
     * several hundred.
     */
    public function test_every_moment_in_a_week_names_the_same_window(): void
    {
        $monday = DigestWindow::containing(DigestCadence::WEEKLY, $this->utc('2026-08-24 00:05:00'));
        $sunday = DigestWindow::containing(DigestCadence::WEEKLY, $this->utc('2026-08-30 21:45:00'));

        $this->assertSame($monday?->key, $sunday?->key);
    }

    /** And the next Monday names a new one, or the digest would stop after the first week. */
    public function test_the_following_monday_opens_a_new_window(): void
    {
        $thisWeek = DigestWindow::containing(DigestCadence::WEEKLY, $this->utc('2026-08-26 12:00:00'));
        $nextWeek = DigestWindow::containing(DigestCadence::WEEKLY, $this->utc('2026-09-02 12:00:00'));

        $this->assertSame('2026-W35', $thisWeek?->key);
        $this->assertSame('2026-W36', $nextWeek?->key);
    }

    /**
     * The bug this format exists to avoid.
     *
     * PHP's `W` is the ISO week number and `o` is the ISO week-numbering year,
     * which is not the calendar year at the turn of one: Monday 29 December
     * 2025 belongs to `2026-W01`. Formatting with `Y` would call it `2025-W01`
     * and then call Monday 5 January `2026-W02`, so the first *calendar* week
     * of January would collide with nothing — but Monday 29 December and
     * Monday 4 January 2027 would both be `W01` of adjacent years and the
     * digest would silently go missing at exactly one turn of the year.
     *
     * Asserted as a property rather than by inspection: two Mondays five weeks
     * apart must never share a key, whatever the calendar is doing.
     */
    public function test_the_turn_of_the_year_does_not_collide(): void
    {
        $keys = [];
        foreach ([
            '2025-12-22', '2025-12-29', '2026-01-05', '2026-01-12', '2026-01-19',
        ] as $monday) {
            $window = DigestWindow::containing(DigestCadence::WEEKLY, $this->utc($monday . ' 08:00:00'));
            $keys[] = $window?->key;
        }

        $this->assertSame($keys, array_values(array_unique($keys)), 'consecutive weeks must not share a window key');
        $this->assertContains('2026-W01', $keys, '29 December 2025 is ISO week 1 of 2026');
    }

    /**
     * The clock is the club's, not UTC.
     *
     * 23:30 UTC on a Sunday in July is 01:30 on Monday in Berlin, so a daily
     * digest belongs to Monday and a weekly one to the week that has just
     * started. Reading the instant in UTC would put both a day early, which is
     * how a "Monday morning" digest arrives on Sunday night.
     */
    public function test_the_window_is_cut_in_the_clubs_own_zone(): void
    {
        $lateSunday = $this->utc('2026-08-23 23:30:00');

        $this->assertSame('2026-08-24', DigestWindow::containing(DigestCadence::DAILY, $lateSunday)?->key);
        $this->assertSame('2026-W35', DigestWindow::containing(DigestCadence::WEEKLY, $lateSunday)?->key);
    }

    /**
     * `AdminNotifier` builds `occasion:adminUserId` into a `VARCHAR(64)`, and an
     * admin id is 36 characters. A key that outgrew its 27 would be truncated
     * by MariaDB, and two recipients could then share a dedup key.
     */
    public function test_a_window_key_fits_the_dedup_column(): void
    {
        foreach ([DigestCadence::DAILY, DigestCadence::WEEKLY, DigestCadence::MONTHLY] as $cadence) {
            $window = DigestWindow::containing($cadence, $this->utc('2026-12-28 12:00:00'));
            $this->assertNotNull($window);
            $this->assertLessThanOrEqual(27, strlen($window->key), $cadence->value);
        }
    }

    private function utc(string $moment): DateTimeImmutable
    {
        return new DateTimeImmutable($moment, new DateTimeZone('UTC'));
    }
}
