<?php

declare(strict_types=1);

namespace Tests\Feature\Shared\Database;

use App\Shared\Database\ConnectionFactory;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The database half of #365.
 *
 * `CURRENT_TIMESTAMP` column defaults are resolved by the server in the session
 * time zone, so pinning PHP alone leaves every `DEFAULT CURRENT_TIMESTAMP`
 * column following whatever zone the host's MariaDB happens to run in.
 */
final class ConnectionFactoryTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = ConnectionFactory::create(
            getenv('DB_HOST') ?: 'database',
            getenv('DB_NAME') ?: 'clubbar',
            getenv('DB_USER') ?: 'clubbar',
            getenv('DB_PASS') ?: 'clubbar',
        );
    }

    public function testConnectionSessionIsPinnedToUtc(): void
    {
        $sessionTimeZone = $this->db->query('SELECT @@session.time_zone')->fetchColumn();

        $this->assertSame('+00:00', $sessionTimeZone);
    }

    /**
     * The connection is pinned regardless of how the *server* is configured, so
     * the session zone must not simply mirror the global one.
     */
    public function testSessionIsPinnedEvenWhenTheServerRunsAnotherZone(): void
    {
        $global = $this->db->query('SELECT @@global.time_zone')->fetchColumn();
        $session = $this->db->query('SELECT @@session.time_zone')->fetchColumn();

        $this->assertSame('+00:00', $session, sprintf(
            'Session zone must be UTC whatever the server default is (global: %s)',
            (string) $global,
        ));
    }

    /** What the server calls "now" must be the same instant PHP calls "now". */
    public function testDatabaseNowMatchesPhpUtcNow(): void
    {
        $databaseNow = (string) $this->db->query('SELECT NOW()')->fetchColumn();

        $drift = abs(strtotime($databaseNow . ' UTC') - time());

        $this->assertLessThan(120, $drift, sprintf(
            'NOW() returned %s, which is %d seconds from the PHP clock — the session zone is not UTC',
            $databaseNow,
            $drift,
        ));
    }

    /**
     * Models `audit_log.created_at`: a DATETIME taking its value from a column
     * default, which is exactly the column the issue's screenshot came from.
     * A temporary table keeps the probe out of real data.
     */
    public function testCurrentTimestampColumnDefaultIsWrittenInUtc(): void
    {
        $this->db->exec(
            'CREATE TEMPORARY TABLE tz_probe (
                id INT PRIMARY KEY,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $this->db->exec('INSERT INTO tz_probe (id) VALUES (1)');

        $storedAt = (string) $this->db->query('SELECT created_at FROM tz_probe WHERE id = 1')->fetchColumn();

        $drift = abs(strtotime($storedAt . ' UTC') - time());

        $this->assertLessThan(120, $drift, sprintf(
            'A DEFAULT CURRENT_TIMESTAMP column stored %s, %d seconds from UTC now. '
            . 'The API labels this value "Z", so the browser would render it that far off (#365).',
            $storedAt,
            $drift,
        ));
    }

    /**
     * Bound LIMIT/OFFSET integers are quoted as strings under emulated
     * prepares, which MariaDB rejects — the setting every entry point now
     * inherits instead of re-declaring.
     */
    public function testNativePreparesAreUsed(): void
    {
        // The driver reports this as an int, not a bool.
        $this->assertFalse((bool) $this->db->getAttribute(PDO::ATTR_EMULATE_PREPARES));
    }
}
