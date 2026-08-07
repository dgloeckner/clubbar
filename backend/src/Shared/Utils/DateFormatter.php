<?php

declare(strict_types=1);

namespace App\Shared\Utils;

/**
 * Converts database datetime strings to ISO 8601 UTC format for API responses.
 *
 * MariaDB stores timestamps without timezone info (e.g. "2026-03-01 09:19:03").
 * The database runs in UTC, so all stored datetimes are UTC.
 * This utility appends the "Z" suffix so frontends parse them correctly
 * and display in the user's local timezone.
 */
final class DateFormatter
{
    /**
     * Convert a DATETIME/TIMESTAMP string to ISO 8601 UTC format.
     *
     * Input:  "2026-03-01 09:19:03" (bare UTC from MariaDB)
     * Output: "2026-03-01T09:19:03Z" (ISO 8601 UTC)
     *
     * Returns null for null input. Returns the original string if parsing fails.
     */
    public static function toUtcIso(?string $datetime): ?string
    {
        if ($datetime === null || $datetime === '') {
            return null;
        }

        $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $datetime);
        if ($dt !== false) {
            return $dt->format('Y-m-d\TH:i:s\Z');
        }

        // Already in ISO 8601 format or other format — return as-is
        return $datetime;
    }

    /**
     * Convert an inbound API timestamp to the bare UTC form MariaDB stores.
     *
     * Input:  "2026-01-23T14:23:15.780Z" (ISO 8601, as terminals send it)
     * Output: "2026-01-23 14:23:15" (bare UTC for a DATETIME column)
     *
     * An offset is applied, not dropped: the columns are UTC. A value MariaDB
     * already accepts is passed through untouched, and so is one that cannot
     * be parsed — inventing a plausible timestamp for a sale whose time we do
     * not know is worse than letting the database refuse the row (#144).
     */
    public static function toMysqlDateTime(?string $datetime): ?string
    {
        if ($datetime === null || $datetime === '') {
            return null;
        }

        try {
            $dt = new \DateTimeImmutable($datetime);
        } catch (\Exception) {
            return $datetime;
        }

        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
