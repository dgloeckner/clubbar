<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\AdminUsers\Repositories;

use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Shared\Logging\Logger;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * AdminUsersRepository runs plain, portable SQL against the `admin_users`
 * table (see db/migrations/001_initial_schema.sql, 004_totp_2fa.sql,
 * 010_totp_replay_protection.sql), so an in-memory SQLite database exercises
 * the real queries without needing the Docker MariaDB instance.
 */
class AdminUsersRepositoryTest extends TestCase
{
    private PDO $db;
    private AdminUsersRepository $repository;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->db->exec(
            'CREATE TABLE admin_users (
                id CHAR(36) NOT NULL PRIMARY KEY,
                email VARCHAR(255) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                display_name VARCHAR(255) NULL,
                locale VARCHAR(10) NOT NULL DEFAULT "de",
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                last_login_at TIMESTAMP NULL,
                totp_secret VARCHAR(255) NULL,
                totp_enabled TINYINT(1) NOT NULL DEFAULT 0,
                totp_last_timestep BIGINT NULL,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL
            )'
        );

        $this->repository = new AdminUsersRepository($this->db, $this->createMock(Logger::class));
        $this->repository->create([
            'id' => 'admin-1',
            'email' => 'admin@example.com',
            'password' => 'hashed',
            'display_name' => 'Test Admin',
        ]);
    }

    public function test_saveTotp_enrolls_and_records_the_confirming_time_step(): void
    {
        $this->repository->saveTotp('admin-1', 'iv:ciphertext', 12345);

        $admin = $this->repository->findById('admin-1');

        $this->assertSame('iv:ciphertext', $admin['totp_secret']);
        $this->assertSame(1, (int) $admin['totp_enabled']);
        $this->assertSame(12345, (int) $admin['totp_last_timestep']);
    }

    public function test_updateTotpLastTimestep_updates_only_that_column(): void
    {
        $this->repository->saveTotp('admin-1', 'iv:ciphertext', 100);

        $this->repository->updateTotpLastTimestep('admin-1', 101);

        $admin = $this->repository->findById('admin-1');
        $this->assertSame(101, (int) $admin['totp_last_timestep']);
        $this->assertSame('iv:ciphertext', $admin['totp_secret']);
        $this->assertSame(1, (int) $admin['totp_enabled']);
    }

    public function test_clearTotp_also_clears_the_replay_tracking_time_step(): void
    {
        $this->repository->saveTotp('admin-1', 'iv:ciphertext', 100);

        $this->repository->clearTotp('admin-1');

        $admin = $this->repository->findById('admin-1');
        $this->assertNull($admin['totp_secret']);
        $this->assertSame(0, (int) $admin['totp_enabled']);
        $this->assertNull($admin['totp_last_timestep']);
    }
}
