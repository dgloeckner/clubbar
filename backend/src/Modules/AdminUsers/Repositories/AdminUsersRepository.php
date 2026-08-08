<?php

declare(strict_types=1);

namespace App\Modules\AdminUsers\Repositories;

use App\Shared\Utils\Uuid;
use PDO;
use App\Shared\Logging\Logger;
use App\Shared\Repository\SafeQuery;

class AdminUsersRepository
{
    public function __construct(
        private PDO $db,
        private Logger $logger,
    ) {}

    public function findById(string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM admin_users WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM admin_users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        return $stmt->fetch() ?: null;
    }

    public function countActive(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM admin_users WHERE is_active = 1')->fetchColumn();
    }

    public function create(array $data): array
    {
        $id = $data['id'] ?? Uuid::v4();
        $now = date('Y-m-d H:i:s');

        $stmt = $this->db->prepare(
            'INSERT INTO admin_users (id, email, password_hash, display_name, locale, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            $data['email'],
            $data['password'],
            $data['display_name'],
            $data['locale'] ?? 'de',
            ($data['is_active'] ?? true) ? 1 : 0,
            $now,
            $now,
        ]);

        $this->logger->info('Admin user created', ['id' => $id]);
        return $this->findById($id);
    }

    public function updateById(string $id, array $data): ?array
    {
        $allowed = ['email', 'password_hash', 'display_name', 'locale', 'is_active', 'last_login_at'];

        // Handle password field -> password_hash mapping
        if (isset($data['password']) && !isset($data['password_hash'])) {
            $data['password_hash'] = $data['password'];
            unset($data['password']);
        }

        [$set, $values] = SafeQuery::buildUpdate($data, $allowed);
        $values[] = date('Y-m-d H:i:s');
        $values[] = $id;

        $stmt = $this->db->prepare("UPDATE admin_users SET {$set}, updated_at = ? WHERE id = ?");
        $stmt->execute($values);

        $this->logger->info('Admin user updated', ['id' => $id]);
        return $this->findById($id);
    }

    /**
     * Persist an encrypted TOTP secret and mark the account as enrolled.
     */
    public function saveTotp(string $id, string $encryptedSecret): void
    {
        $stmt = $this->db->prepare(
            'UPDATE admin_users SET totp_secret = ?, totp_enabled = 1, updated_at = ? WHERE id = ?'
        );
        $stmt->execute([$encryptedSecret, date('Y-m-d H:i:s'), $id]);
        $this->logger->info('Admin user TOTP enrolled', ['id' => $id]);
    }

    /**
     * Remove TOTP credentials and mark the account as unenrolled.
     */
    public function clearTotp(string $id): void
    {
        $stmt = $this->db->prepare(
            'UPDATE admin_users SET totp_secret = NULL, totp_enabled = 0, updated_at = ? WHERE id = ?'
        );
        $stmt->execute([date('Y-m-d H:i:s'), $id]);
        $this->logger->info('Admin user TOTP reset', ['id' => $id]);
    }

    public function listPaginated(int $limit, int $offset, array $filters = []): array
    {
        $where = [];
        $params = [];

        if (isset($filters['status'])) {
            if ($filters['status'] === 'active') { $where[] = 'is_active = 1'; }
            elseif ($filters['status'] === 'inactive') { $where[] = 'is_active = 0'; }
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM admin_users {$whereClause}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $dataParams = array_merge($params, [$limit, $offset]);
        $stmt = $this->db->prepare("SELECT * FROM admin_users {$whereClause} ORDER BY created_at DESC LIMIT ? OFFSET ?");
        $stmt->execute($dataParams);

        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }
}
