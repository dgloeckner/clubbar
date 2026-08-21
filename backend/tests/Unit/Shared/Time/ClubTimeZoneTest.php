<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Time;

use App\Shared\Config\Env;
use App\Shared\Time\ClubTimeZone;
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
}
