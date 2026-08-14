<?php

declare(strict_types=1);

namespace App\Shared\Database;

use App\Shared\Time\Utc;
use PDO;

/**
 * Builds the application's database connections.
 *
 * Four entry points — `bootstrap.php`, `public/install.php`,
 * `bin/import-bank-codes.php` and the test harness — each repeated the same DSN
 * and option list, and had already drifted: two ran with emulated prepares, and
 * none pinned a session time zone.
 *
 * That last omission is why this class exists (#365). `CURRENT_TIMESTAMP` and
 * `NOW()` are evaluated by the server in the *session* time zone, which defaults
 * to the server's own. On a host running Europe/Berlin, a row taking its
 * `created_at` from a column default is written with Berlin local time, which
 * the API then labels "Z" — the browser adds the offset a second time and shows
 * a timestamp two hours in the future.
 *
 * `TIMESTAMP` columns are worth separating from `DATETIME` here, because the fix
 * reaches further than it first appears. MariaDB converts a `TIMESTAMP` to UTC
 * on write and back on read using the session zone, so those columns hold the
 * correct instant even on a Berlin server — they were only ever *read* wrong,
 * and pinning the session repairs existing rows as well as new ones. `DATETIME`
 * columns (`audit_log.created_at` among them) store the literal string handed to
 * them, so for those the fix applies from here on and rows already written keep
 * whatever offset they were written with.
 */
final class ConnectionFactory
{
    /**
     * Connect with the options every entry point should share.
     */
    public static function create(
        string $host,
        string $database,
        string $user,
        string $password,
    ): PDO {
        return new PDO(
            sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $database),
            $user,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Emulated prepares quote bound LIMIT/OFFSET integers as strings,
                // which MariaDB rejects. Native prepares avoid this.
                PDO::ATTR_EMULATE_PREPARES => false,
                // Issued by the client library as part of connecting, so no query
                // can run against this handle before the zone is set.
                PDO::MYSQL_ATTR_INIT_COMMAND => sprintf("SET time_zone = '%s'", Utc::SQL_OFFSET),
            ]
        );
    }
}
