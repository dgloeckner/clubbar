<?php

declare(strict_types=1);

namespace App\Modules\Auth\Repositories;

use PDO;
use App\Shared\Logging\Logger;

class SessionRepository
{
    public function __construct(
        private PDO $db,
        private Logger $logger,
    ) {}

    public function findById(string $sessionId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM sessions WHERE id = ?');
        $stmt->execute([$sessionId]);
        return $stmt->fetch() ?: null;
    }

    public function create(string $sessionId, string $adminUserId, string $ipAddress, string $userAgent): void
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            'INSERT INTO sessions (id, admin_user_id, ip_address, user_agent, last_activity_at, created_at) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$sessionId, $adminUserId, $ipAddress, $userAgent, $now, $now]);
    }

    public function updateActivity(string $sessionId): void
    {
        $stmt = $this->db->prepare('UPDATE sessions SET last_activity_at = ? WHERE id = ?');
        $stmt->execute([date('Y-m-d H:i:s'), $sessionId]);
    }

    public function delete(string $sessionId): void
    {
        $this->db->prepare('DELETE FROM sessions WHERE id = ?')->execute([$sessionId]);
    }

    public function deleteByAdminUser(string $adminUserId): void
    {
        $this->db->prepare('DELETE FROM sessions WHERE admin_user_id = ?')->execute([$adminUserId]);
    }

    public function deleteExpired(int $maxAgeSeconds): int
    {
        $cutoff = date('Y-m-d H:i:s', time() - $maxAgeSeconds);
        $stmt = $this->db->prepare('DELETE FROM sessions WHERE last_activity_at < ?');
        $stmt->execute([$cutoff]);
        return $stmt->rowCount();
    }
}
