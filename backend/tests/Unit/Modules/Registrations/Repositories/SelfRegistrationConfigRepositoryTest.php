<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Registrations\Repositories;

use App\Modules\Registrations\Repositories\SelfRegistrationConfigRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/** Hand-maintained copy of migration `059`'s `self_registration_config`. */
final class SelfRegistrationConfigRepositoryTest extends TestCase
{
    private PDO $db;
    private SelfRegistrationConfigRepository $repository;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->db->exec(
            'CREATE TABLE self_registration_config (
                id INTEGER NOT NULL PRIMARY KEY,
                enabled INTEGER NOT NULL DEFAULT 0,
                disabled_reason VARCHAR(500) NULL,
                secret_hash CHAR(64) NULL,
                secret_cipher TEXT NULL,
                secret_rotated_at DATETIME NULL,
                retention_days INTEGER NOT NULL DEFAULT 30,
                updated_by_admin_id CHAR(36) NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )'
        );
        $this->db->exec('INSERT INTO self_registration_config (id, enabled) VALUES (1, 0)');

        $this->repository = new SelfRegistrationConfigRepository($this->db);
    }

    /**
     * The shipped state. Both halves matter: no secret means no poster can
     * work, and `enabled = false` means even the right secret is refused.
     */
    public function test_a_fresh_installation_is_disabled_with_no_secret(): void
    {
        $config = $this->repository->get();

        self::assertFalse($config->enabled);
        self::assertNull($config->secretHash);
        self::assertNull($config->disabledReason);
        self::assertSame(30, $config->retentionDays);
    }

    /**
     * A row missing entirely — a database predating the migration, or one an
     * operator truncated — must read as the shipped state rather than as an
     * error or, worse, as enabled.
     */
    public function test_a_missing_row_reads_as_the_fail_closed_default(): void
    {
        $this->db->exec('DELETE FROM self_registration_config');

        $config = $this->repository->get();

        self::assertFalse($config->enabled);
        self::assertNull($config->secretHash);
        self::assertSame(30, $config->retentionDays);
    }

    public function test_it_reads_back_what_was_configured(): void
    {
        $this->db->exec(
            "UPDATE self_registration_config
             SET enabled = 1, secret_hash = '" . str_repeat('a', 64) . "',
                 disabled_reason = 'Beta-Phase schon voll', retention_days = 14
             WHERE id = 1"
        );

        $config = $this->repository->get();

        self::assertTrue($config->enabled);
        self::assertSame(str_repeat('a', 64), $config->secretHash);
        self::assertSame('Beta-Phase schon voll', $config->disabledReason);
        self::assertSame(14, $config->retentionDays);
    }
}
