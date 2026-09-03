<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Time;

use App\Shared\Config\Env;
use App\Shared\Time\ClubLocalSql;
use App\Shared\Time\ClubTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The aggregation half of #365: a chart bucketed by the club's calendar.
 */
final class ClubLocalSqlTest extends TestCase
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

    /**
     * A range wholly inside summer time needs no CASE at all — Berlin is
     * +02:00 for every row in it.
     */
    public function testARangeInOneOffsetIsAPlainInterval(): void
    {
        $sql = ClubLocalSql::localInstant('t.occurred_at', '2026-09-01 00:00:00', '2026-09-30 23:59:59');

        $this->assertSame('(t.occurred_at + INTERVAL 7200 SECOND)', $sql);
    }

    /** And a winter range is +01:00. */
    public function testAWinterRangeIsTheWinterOffset(): void
    {
        $sql = ClubLocalSql::localInstant('t.occurred_at', '2026-01-05 00:00:00', '2026-01-31 23:59:59');

        $this->assertSame('(t.occurred_at + INTERVAL 3600 SECOND)', $sql);
    }

    /**
     * The case the whole class exists for: a range crossing the October
     * transition buckets rows on either side of it by different offsets.
     * Berlin goes back at 01:00 UTC on 2026-10-25.
     */
    public function testARangeCrossingATransitionSwitchesOffsetAtIt(): void
    {
        $sql = ClubLocalSql::localInstant('t.occurred_at', '2026-10-01 00:00:00', '2026-11-30 23:59:59');

        $this->assertSame(
            "(t.occurred_at + INTERVAL CASE WHEN t.occurred_at < '2026-10-25 01:00:00' THEN 7200 ELSE 3600 END SECOND)",
            $sql,
        );
    }

    /** A year covers both transitions, so it takes two WHEN arms. */
    public function testAFullYearCoversBothTransitions(): void
    {
        $sql = ClubLocalSql::localInstant('t.occurred_at', '2026-01-01 00:00:00', '2026-12-31 23:59:59');

        $this->assertSame(
            '(t.occurred_at + INTERVAL CASE'
            . " WHEN t.occurred_at < '2026-03-29 01:00:00' THEN 3600"
            . " WHEN t.occurred_at < '2026-10-25 01:00:00' THEN 7200"
            . ' ELSE 3600 END SECOND)',
            $sql,
        );
    }

    /** A club that configures a zone without daylight saving gets no CASE. */
    public function testAZoneWithoutDaylightSavingIsAlwaysAPlainInterval(): void
    {
        $_ENV[ClubTimeZone::ENV_KEY] = 'UTC';
        Env::reset();

        $sql = ClubLocalSql::localInstant('t.occurred_at', '2026-01-01 00:00:00', '2026-12-31 23:59:59');

        $this->assertSame('(t.occurred_at + INTERVAL 0 SECOND)', $sql);
    }

    /**
     * An unbounded query still produces valid SQL rather than throwing — the
     * arms are capped, not the correctness of the range that has data.
     */
    public function testAnUnboundedRangeStillBuildsAnExpression(): void
    {
        $sql = ClubLocalSql::localInstant('t.occurred_at');

        $this->assertStringStartsWith('(t.occurred_at + INTERVAL CASE', $sql);
        $this->assertStringEndsWith('END SECOND)', $sql);
        $this->assertStringContainsString('THEN 7200', $sql);
        $this->assertStringContainsString('THEN 3600', $sql);
    }

    /**
     * Nothing caller-supplied reaches the expression, and the one identifier
     * that does is checked rather than trusted.
     */
    public function testAColumnThatIsNotAnIdentifierIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ClubLocalSql::localInstant("t.occurred_at) OR 1=1 --");
    }

    /** A backwards range is a caller bug, not a reason to emit broken SQL. */
    public function testABackwardsRangeIsStillValidSql(): void
    {
        $sql = ClubLocalSql::localInstant('t.occurred_at', '2026-09-30 00:00:00', '2026-09-01 00:00:00');

        $this->assertSame('(t.occurred_at + INTERVAL 7200 SECOND)', $sql);
    }
}
