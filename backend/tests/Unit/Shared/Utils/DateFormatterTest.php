<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Utils;

use App\Shared\Utils\DateFormatter;
use PHPUnit\Framework\TestCase;

class DateFormatterTest extends TestCase
{
    public function test_toUtcIso_appends_the_utc_marker_to_a_bare_database_datetime(): void
    {
        $this->assertSame('2026-03-01T09:19:03Z', DateFormatter::toUtcIso('2026-03-01 09:19:03'));
    }

    public function test_toUtcIso_returns_null_for_no_datetime(): void
    {
        $this->assertNull(DateFormatter::toUtcIso(null));
        $this->assertNull(DateFormatter::toUtcIso(''));
    }

    /**
     * The inbound half of the same conversion. Terminals send `created_at` as
     * ISO 8601 with a `Z` and milliseconds — a value MariaDB cannot store.
     * `INSERT IGNORE` used to hide that: it coerced the string and raised a
     * truncation warning nobody read (#82). With a plain INSERT the same value
     * is an error, so the conversion has to be explicit.
     */
    public function test_toMysqlDateTime_converts_an_iso_8601_utc_timestamp(): void
    {
        $this->assertSame('2026-08-07 21:45:35', DateFormatter::toMysqlDateTime('2026-08-07T21:45:35.780Z'));
        $this->assertSame('2026-01-23 14:23:15', DateFormatter::toMysqlDateTime('2026-01-23T14:23:15Z'));
    }

    public function test_toMysqlDateTime_normalises_an_offset_to_utc(): void
    {
        // 16:23:15+02:00 is 14:23:15 UTC — the column is UTC, so the offset
        // must be applied rather than dropped.
        $this->assertSame('2026-01-23 14:23:15', DateFormatter::toMysqlDateTime('2026-01-23T16:23:15+02:00'));
    }

    public function test_toMysqlDateTime_passes_through_a_value_already_in_storage_format(): void
    {
        $this->assertSame('2026-01-23 14:23:15', DateFormatter::toMysqlDateTime('2026-01-23 14:23:15'));
    }

    /**
     * Garbage stays garbage. Silently substituting "now" for an unparseable
     * sale time would invent a timestamp the terminal never claimed; the row
     * must be refused instead (ruling #144 — flag, never rewrite).
     */
    public function test_toMysqlDateTime_returns_an_unparseable_value_unchanged(): void
    {
        $this->assertSame('not-a-date', DateFormatter::toMysqlDateTime('not-a-date'));
        $this->assertNull(DateFormatter::toMysqlDateTime(null));
    }
}
