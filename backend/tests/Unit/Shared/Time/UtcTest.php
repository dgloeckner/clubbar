<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Time;

use App\Shared\Time\Utc;
use PHPUnit\Framework\TestCase;

final class UtcTest extends TestCase
{
    private string $originalTimezone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalTimezone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->originalTimezone);
        parent::tearDown();
    }

    /**
     * The host in #365 was configured for Europe/Berlin, which is what made
     * `date('Y-m-d H:i:s')` in the repositories write local time.
     */
    public function testApplyOverridesANonUtcHostTimezone(): void
    {
        date_default_timezone_set('Europe/Berlin');

        Utc::apply();

        $this->assertSame('UTC', date_default_timezone_get());
    }

    public function testApplyIsIdempotent(): void
    {
        Utc::apply();
        Utc::apply();

        $this->assertSame('UTC', date_default_timezone_get());
    }

    /**
     * With the runtime pinned, the formatter the repositories use produces the
     * same instant as an explicit UTC format — which is the property the "Z"
     * suffix added by `DateFormatter::toUtcIso()` claims.
     */
    public function testDateAgreesWithGmdateOncePinned(): void
    {
        date_default_timezone_set('Europe/Berlin');
        Utc::apply();

        $this->assertSame(gmdate('Y-m-d H:i'), date('Y-m-d H:i'));
    }

    /**
     * Named zones need the `mysql.time_zone` tables populated, which a default
     * MariaDB install does not do — `SET time_zone = 'UTC'` would fail there.
     */
    public function testSqlOffsetIsNumericRatherThanAZoneName(): void
    {
        $this->assertSame('+00:00', Utc::SQL_OFFSET);
    }
}
