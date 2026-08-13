<?php

declare(strict_types=1);

namespace App\Shared\Time;

/**
 * Club Bar keeps every instant in UTC, end to end.
 *
 * Columns hold UTC, `DateFormatter::toUtcIso()` labels them "Z" on the way out,
 * and the browser converts to the reader's local time. That chain only holds if
 * the value written was *actually* UTC — and nothing guaranteed it. PHP's
 * default time zone comes from `date.timezone` in a `php.ini` we do not own, so
 * on a host set to Europe/Berlin every `date('Y-m-d H:i:s')` in the repositories
 * wrote Berlin local time into a column the API then labelled as UTC. An admin
 * logging in at 18:46 CEST was stored as "18:46", labelled "18:46Z", and
 * rendered by the browser as 20:46 (#365).
 *
 * Pinning the runtime here fixes every one of those call sites at once, and does
 * it in code rather than in configuration for the reason ADR-0031 gives: on
 * mass hosting there is no `php.ini` we may edit and no guarantee a `.user.ini`
 * we ship is read at all. `date.timezone` is settable at runtime, so the
 * application can make the guarantee itself on any host.
 *
 * The database half of the same decision lives in
 * `App\Shared\Database\ConnectionFactory`, which pins the session time zone —
 * `CURRENT_TIMESTAMP` column defaults are resolved by the server, not by PHP,
 * so neither half is sufficient alone.
 */
final class Utc
{
    /**
     * The session time zone for database connections.
     *
     * A numeric offset, not the name "UTC": named zones require the
     * `mysql.time_zone` tables to be populated, which a default MariaDB install
     * does not do and which we cannot arrange on shared hosting. `SET time_zone
     * = 'UTC'` fails outright there, while the offset always resolves.
     */
    public const SQL_OFFSET = '+00:00';

    /**
     * Pin the PHP runtime to UTC.
     *
     * Call this before anything reads the clock — which includes the Logger,
     * whose daily log files are named after the current date.
     */
    public static function apply(): void
    {
        date_default_timezone_set('UTC');
    }
}
