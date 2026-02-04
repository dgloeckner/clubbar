<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;
use App\Logging\Logger;

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

    public function findAll(): array
    {
        return $this->db->query('SELECT * FROM admin_users ORDER BY created_at ASC')->fetchAll();
    }

    public function findActive(): array
    {
        return $this->db->query('SELECT * FROM admin_users WHERE is_active = 1 ORDER BY created_at ASC')->fetchAll();
    }

    public function countActive(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM admin_users WHERE is_active = 1')->fetchColumn();
    }

    public function create(array $data): array
    {
        $id = $data['id'] ?? $this->generateUuid();
        $now = date('Y-m-d H:i:s');

        $stmt = $this->db->prepare(
            'INSERT INTO admin_users (id, email, password, display_name, locale, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
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
        $allowed = ['email', 'password', 'display_name', 'locale', 'is_active', 'last_login_at'];
        [$set, $values] = SafeQuery::buildUpdate($data, $allowed);
        $values[] = date('Y-m-d H:i:s');
        $values[] = $id;

        $stmt = $this->db->prepare("UPDATE admin_users SET {$set}, updated_at = ? WHERE id = ?");
        $stmt->execute($values);

        $this->logger->info('Admin user updated', ['id' => $id]);
        return $this->findById($id);
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
        $stmt = $this->db->prepare("SELECT * FROM admin_users {$whereClause} ORDER BY created_at ASC LIMIT ? OFFSET ?");
        $stmt->execute($dataParams);

        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
