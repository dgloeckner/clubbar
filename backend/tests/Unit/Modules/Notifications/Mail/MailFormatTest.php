<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Notifications\Mail;

use App\Modules\Notifications\Enums\MailLanguage;
use App\Modules\Notifications\Mail\MailFormat;
use App\Shared\Config\Env;
use App\Shared\Time\ClubTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * Which clock a mail writes a date and a time in (#637).
 *
 * The runtime is pinned to UTC (#365), so `date()` on a stored value printed
 * UTC on every host — two hours early in a German summer — and there is no
 * browser at drain time to finish the conversion the way the admin UI does. The
 * calendar-day cases are the other half of the same rule: a due date is a day,
 * not an instant, and must survive the trip unshifted.
 *
 * Money and the account mask are covered by `MailStringsTest`, which had this
 * class's simpler half before there was a file for it.
 */
final class MailFormatTest extends TestCase
{
    private string $originalTimezone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalTimezone = date_default_timezone_get();
        // What bootstrap.php does on every request, and the condition that made
        // the bug host-independent.
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

    public function testACalendarDayKeepsItsDate(): void
    {
        $this->assertSame('21.08.2026', MailFormat::date('2026-08-21', MailLanguage::German));
        $this->assertSame('21 August 2026', MailFormat::date('2026-08-21', MailLanguage::English));
    }

    /** The reported defect: a sale at 16:29:57 CEST, stored as 14:29:57Z. */
    public function testAnInstantIsWrittenInTheClubsZone(): void
    {
        $this->assertSame(
            '21.08.2026, 16:29',
            MailFormat::dateTime('2026-08-21 14:29:57', MailLanguage::German),
        );
    }

    public function testTheOffsetFollowsDaylightSaving(): void
    {
        $this->assertSame(
            '21.01.2026, 15:29',
            MailFormat::dateTime('2026-01-21 14:29:57', MailLanguage::German),
        );
    }

    /**
     * A sale after 22:00 UTC belongs to the club's next day — which is the day
     * the Deckelauszug and the pre-notification must list it under: both render
     * a transaction's instant through `MailFormat::date()`.
     */
    public function testALateInstantIsListedUnderTheLocalDay(): void
    {
        $this->assertSame('22.08.2026', MailFormat::date('2026-08-21 23:30:00', MailLanguage::German));
        $this->assertSame('22 August 2026', MailFormat::date('2026-08-21 23:30:00', MailLanguage::English));
    }

    public function testTheZoneIsConfigurable(): void
    {
        $_ENV[ClubTimeZone::ENV_KEY] = 'UTC';

        $this->assertSame(
            '21.08.2026, 14:29',
            MailFormat::dateTime('2026-08-21 14:29:57', MailLanguage::German),
        );
    }

    /**
     * A due date must not move. `2026-08-21` is the 21st for a member checking
     * their account, whatever zone the club reads in — including one west of
     * Greenwich, where shifting it would print the 20th.
     */
    public function testADueDateDoesNotMoveInAWesternZone(): void
    {
        $_ENV[ClubTimeZone::ENV_KEY] = 'America/New_York';

        $this->assertSame('21.08.2026', MailFormat::date('2026-08-21', MailLanguage::German));
    }

    /** Blank stays blank; unparseable comes back verbatim rather than empty. */
    public function testDegradedInputs(): void
    {
        $this->assertSame('', MailFormat::date(null, MailLanguage::German));
        $this->assertSame('', MailFormat::date('  ', MailLanguage::German));
        $this->assertSame('', MailFormat::dateTime(null, MailLanguage::German));
        $this->assertSame('kein Datum', MailFormat::date('kein Datum', MailLanguage::German));
        $this->assertSame('kein Datum', MailFormat::dateTime('kein Datum', MailLanguage::German));
    }
}
