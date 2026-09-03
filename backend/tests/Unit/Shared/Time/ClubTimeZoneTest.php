<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Time;

use App\Shared\Config\Env;
use App\Shared\Time\ClubTimeZone;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * The reading half of #365 (#637): storage is UTC, display is the club's zone.
 */
final class ClubTimeZoneTest extends TestCase
{
    private string $originalTimezone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalTimezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        Env::reset();
        unset($_ENV[ClubTimeZone::ENV_KEY]);
        putenv(ClubTimeZone::ENV_KEY);
    }

    protected function tearDown(): void
    {
        unset($_ENV[ClubTimeZone::ENV_KEY]);
        putenv(ClubTimeZone::ENV_KEY);
        Env::reset();
        date_default_timezone_set($this->originalTimezone);
        parent::tearDown();
    }

    public function testAClubThatConfiguresNothingIsInBerlin(): void
    {
        $this->assertSame('Europe/Berlin', ClubTimeZone::name());
    }

    public function testTheZoneIsConfigurable(): void
    {
        $_ENV[ClubTimeZone::ENV_KEY] = 'Europe/Lisbon';

        $this->assertSame('Europe/Lisbon', ClubTimeZone::name());
    }

    /**
     * A typo must not take the notice down: a mail whose one job is to reach
     * somebody is worth more than a mail that is exactly right about the hour.
     */
    public function testAnUnknownZoneFallsBackToTheDefaultRatherThanThrowing(): void
    {
        $_ENV[ClubTimeZone::ENV_KEY] = 'Europe/Ruderbar';

        $this->assertSame('Europe/Berlin', ClubTimeZone::name());
        $this->assertSame('Europe/Berlin', ClubTimeZone::zone()->getName());
    }

    /** The bug in #637, as a single assertion. */
    public function testAStoredInstantIsReadInTheClubsZone(): void
    {
        $moment = ClubTimeZone::moment('2026-08-21 14:29:57');

        $this->assertNotNull($moment);
        $this->assertSame('2026-08-21 16:29:57', $moment->format('Y-m-d H:i:s'));
    }

    /** Winter, so the offset is one hour rather than two — CET, not CEST. */
    public function testTheOffsetFollowsDaylightSaving(): void
    {
        $moment = ClubTimeZone::moment('2026-01-21 14:29:57');

        $this->assertNotNull($moment);
        $this->assertSame('2026-01-21 15:29:57', $moment->format('Y-m-d H:i:s'));
    }

    /** A late sale belongs to the local day, which is the next one. */
    public function testAnInstantMayCrossTheDateBoundary(): void
    {
        $moment = ClubTimeZone::moment('2026-08-21 23:30:00');

        $this->assertNotNull($moment);
        $this->assertSame('2026-08-22 01:30:00', $moment->format('Y-m-d H:i:s'));
    }

    /** An explicit offset in the value is respected rather than overwritten. */
    public function testAValueThatNamesItsOwnZoneIsConvertedFromThatZone(): void
    {
        $moment = ClubTimeZone::moment('2026-08-21T14:29:57Z');

        $this->assertNotNull($moment);
        $this->assertSame('2026-08-21 16:29:57', $moment->format('Y-m-d H:i:s'));
    }

    /**
     * A calendar day is not an instant. A due date of `2026-08-21` is the 21st
     * everywhere, and shifting it by an offset is how a deadline moves a day.
     */
    public function testADateOnlyValueKeepsItsCalendarDay(): void
    {
        $moment = ClubTimeZone::moment('2026-08-21');

        $this->assertNotNull($moment);
        $this->assertSame('2026-08-21 00:00:00', $moment->format('Y-m-d H:i:s'));
        $this->assertSame('Europe/Berlin', $moment->getTimezone()->getName());
    }

    public function testEmptyAndUnparseableValuesAreNull(): void
    {
        $this->assertNull(ClubTimeZone::moment(null));
        $this->assertNull(ClubTimeZone::moment(''));
        $this->assertNull(ClubTimeZone::moment('   '));
        $this->assertNull(ClubTimeZone::moment('nicht wirklich ein Datum'));
    }

    /**
     * `moment()` must not be a clock. `new DateTimeImmutable('now')` parses,
     * so without a guard a stray `'now'` would render as a plausible time
     * instead of falling back to the raw value.
     */
    public function testRelativeExpressionsAreNotAccepted(): void
    {
        $this->assertNull(ClubTimeZone::moment('now'));
        $this->assertNull(ClubTimeZone::moment('+1 day'));
    }

    /**
     * The runtime stays pinned to UTC (#365): reading a value in the club's
     * zone is an explicit conversion, never a change to the default zone that
     * the ~40 repository writes using `date()` would then inherit.
     */
    public function testReadingDoesNotMoveThePinnedRuntimeZone(): void
    {
        ClubTimeZone::moment('2026-08-21 14:29:57');

        $this->assertSame('UTC', date_default_timezone_get());
        $this->assertSame(gmdate('Y-m-d H:i'), date('Y-m-d H:i'));
    }

    /**
     * The club's calendar day, not UTC's. A sale at 23:30 UTC on the 2nd was
     * rung up at 01:30 on the 3rd by everyone who was standing there, and the
     * day's takings have to say so.
     */
    public function testTheDayOfAnInstantIsTheDayTheClubWasIn(): void
    {
        $this->assertSame(
            '2026-09-03',
            ClubTimeZone::dayOf(new DateTimeImmutable('2026-09-02 23:30:00', new DateTimeZone('UTC'))),
        );
        $this->assertSame(
            '2026-09-02',
            ClubTimeZone::dayOf(new DateTimeImmutable('2026-09-02 12:00:00', new DateTimeZone('UTC'))),
        );
    }

    /**
     * A club day is a half-open interval of UTC instants. Berlin in September
     * is +02:00, so the 2nd runs from 22:00 on the 1st to 22:00 on the 2nd.
     */
    public function testAClubDayIsAHalfOpenRangeOfUtcInstants(): void
    {
        $this->assertSame('2026-09-01 22:00:00', ClubTimeZone::startsAtUtc('2026-09-02'));
        $this->assertSame('2026-09-02 22:00:00', ClubTimeZone::endsBeforeUtc('2026-09-02'));
    }

    /**
     * The two days a year that are not 24 hours long. Spring forward loses an
     * hour, autumn back gains one — arithmetic that adding a fixed offset gets
     * wrong and that constructing both boundaries in the zone gets right.
     */
    public function testTheShortAndLongDaysAreTheRightLength(): void
    {
        $spring = strtotime(ClubTimeZone::endsBeforeUtc('2026-03-29'))
            - strtotime(ClubTimeZone::startsAtUtc('2026-03-29'));
        $autumn = strtotime(ClubTimeZone::endsBeforeUtc('2026-10-25'))
            - strtotime(ClubTimeZone::startsAtUtc('2026-10-25'));

        $this->assertSame(23 * 3600, $spring);
        $this->assertSame(25 * 3600, $autumn);
    }

    /**
     * Week and month boundaries are the club's too, and they are derived from
     * the club's *today* rather than from UTC's — the two disagree for the
     * first two hours of a Berlin Monday, which is exactly when a week-to-date
     * figure would otherwise silently still be reporting last week.
     */
    public function testWeekAndMonthStartsAreClubDays(): void
    {
        $justAfterMidnightBerlin = new DateTimeImmutable('2026-08-31 22:30:00', new DateTimeZone('UTC'));

        $this->assertSame('2026-09-01', ClubTimeZone::today($justAfterMidnightBerlin));
        $this->assertSame('2026-08-31', ClubTimeZone::startOfWeek($justAfterMidnightBerlin));
        $this->assertSame('2026-09-01', ClubTimeZone::startOfMonth($justAfterMidnightBerlin));
    }

    public function testAnUnparseableDayHasNoBoundaries(): void
    {
        $this->assertNull(ClubTimeZone::startsAtUtc('nope'));
        $this->assertNull(ClubTimeZone::endsBeforeUtc('2026-13-45'));
    }

    /**
     * The fallback is silent where it is used and reportable here. A club that
     * never stated its zone is reading its books on Berlin's clock by accident,
     * and one that stated `Europe/Berlim` is doing so having tried — neither is
     * visible on any screen, because a wrong hour looks like a right one.
     */
    public function testAnUnstatedZoneIsReportedAsTheDefault(): void
    {
        $this->assertSame(ClubTimeZone::SOURCE_DEFAULT, ClubTimeZone::source());
        $this->assertSame('Europe/Berlin', ClubTimeZone::name());
    }

    public function testABlankZoneIsAlsoTheDefault(): void
    {
        $_ENV[ClubTimeZone::ENV_KEY] = '   ';
        Env::reset();

        $this->assertSame(ClubTimeZone::SOURCE_DEFAULT, ClubTimeZone::source());
    }

    public function testAStatedZoneIsReportedAsConfigured(): void
    {
        $_ENV[ClubTimeZone::ENV_KEY] = 'Europe/Vienna';
        Env::reset();

        $this->assertSame(ClubTimeZone::SOURCE_CONFIGURED, ClubTimeZone::source());
        $this->assertSame('Europe/Vienna', ClubTimeZone::name());
    }

    /** Stating the default explicitly is a decision, not an accident. */
    public function testStatingTheDefaultExplicitlyStillCountsAsConfigured(): void
    {
        $_ENV[ClubTimeZone::ENV_KEY] = 'Europe/Berlin';
        Env::reset();

        $this->assertSame(ClubTimeZone::SOURCE_CONFIGURED, ClubTimeZone::source());
    }

    public function testATypoIsReportedAsInvalidRatherThanAsTheDefault(): void
    {
        $_ENV[ClubTimeZone::ENV_KEY] = 'Europe/Berlim';
        Env::reset();

        $this->assertSame(ClubTimeZone::SOURCE_INVALID, ClubTimeZone::source());
        $this->assertSame('Europe/Berlin', ClubTimeZone::name());
    }
}
