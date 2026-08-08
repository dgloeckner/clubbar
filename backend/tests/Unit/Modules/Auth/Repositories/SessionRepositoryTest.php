<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Auth\Repositories;

use App\Modules\Auth\Repositories\SessionRepository;
use App\Shared\Logging\Logger;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * SessionRepository runs plain, portable SQL against the `sessions` table
 * (see db/migrations/001_initial_schema.sql), so an in-memory SQLite database
 * exercises the real queries without needing the Docker MariaDB instance.
 */
class SessionRepositoryTest extends TestCase
{
    private PDO $db;
    private SessionRepository $sessionRepository;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->db->exec(
            'CREATE TABLE sessions (
                id VARCHAR(255) NOT NULL PRIMARY KEY,
                admin_user_id CHAR(36) NOT NULL,
                ip_address VARCHAR(45) NULL,
                user_agent TEXT NULL,
                last_activity_at TIMESTAMP NOT NULL,
                created_at TIMESTAMP NOT NULL
            )'
        );

        $this->sessionRepository = new SessionRepository($this->db, $this->createMock(Logger::class));
    }

    public function test_findById_returns_null_when_missing(): void
    {
        $this->assertNull($this->sessionRepository->findById('missing'));
    }

    public function test_create_then_findById_returns_the_session(): void
    {
        $this->sessionRepository->create('sess-1', 'admin-1', '127.0.0.1', 'TestAgent/1.0');

        $session = $this->sessionRepository->findById('sess-1');

        $this->assertNotNull($session);
        $this->assertSame('sess-1', $session['id']);
        $this->assertSame('admin-1', $session['admin_user_id']);
        $this->assertSame('127.0.0.1', $session['ip_address']);
        $this->assertSame('TestAgent/1.0', $session['user_agent']);
        $this->assertNotEmpty($session['last_activity_at']);
        $this->assertNotEmpty($session['created_at']);
    }

    public function test_updateActivity_bumps_last_activity_at(): void
    {
        $this->sessionRepository->create('sess-1', 'admin-1', '127.0.0.1', 'TestAgent/1.0');
        // Backdate the session so the update is observably different.
        $this->db->prepare('UPDATE sessions SET last_activity_at = ? WHERE id = ?')
            ->execute(['2000-01-01 00:00:00', 'sess-1']);

        $this->sessionRepository->updateActivity('sess-1');

        $session = $this->sessionRepository->findById('sess-1');
        $this->assertNotSame('2000-01-01 00:00:00', $session['last_activity_at']);
    }

    public function test_updateActivity_on_unknown_session_is_a_no_op(): void
    {
        $this->sessionRepository->updateActivity('missing');

        $this->assertNull($this->sessionRepository->findById('missing'));
    }

    public function test_delete_removes_the_session(): void
    {
        $this->sessionRepository->create('sess-1', 'admin-1', '127.0.0.1', 'TestAgent/1.0');

        $this->sessionRepository->delete('sess-1');

        $this->assertNull($this->sessionRepository->findById('sess-1'));
    }

    public function test_deleteByAdminUser_removes_all_sessions_for_that_admin_only(): void
    {
        $this->sessionRepository->create('sess-1', 'admin-1', '127.0.0.1', 'A');
        $this->sessionRepository->create('sess-2', 'admin-1', '127.0.0.1', 'A');
        $this->sessionRepository->create('sess-3', 'admin-2', '127.0.0.1', 'A');

        $this->sessionRepository->deleteByAdminUser('admin-1');

        $this->assertNull($this->sessionRepository->findById('sess-1'));
        $this->assertNull($this->sessionRepository->findById('sess-2'));
        $this->assertNotNull($this->sessionRepository->findById('sess-3'));
    }

    public function test_deleteExpired_removes_sessions_older_than_max_age_and_returns_count(): void
    {
        $this->sessionRepository->create('stale', 'admin-1', '127.0.0.1', 'A');
        $this->sessionRepository->create('fresh', 'admin-1', '127.0.0.1', 'A');
        $this->db->prepare('UPDATE sessions SET last_activity_at = ? WHERE id = ?')
            ->execute([date('Y-m-d H:i:s', time() - 7300), 'stale']);

        $deleted = $this->sessionRepository->deleteExpired(7200);

        $this->assertSame(1, $deleted);
        $this->assertNull($this->sessionRepository->findById('stale'));
        $this->assertNotNull($this->sessionRepository->findById('fresh'));
    }

    public function test_deleteExpired_returns_zero_when_nothing_is_stale(): void
    {
        $this->sessionRepository->create('fresh', 'admin-1', '127.0.0.1', 'A');

        $this->assertSame(0, $this->sessionRepository->deleteExpired(7200));
    }
}
