<?php

declare(strict_types=1);

namespace App\Modules\Terminals\Repositories;

use PDO;
use App\Shared\Logging\Logger;
use App\Shared\Repository\SafeQuery;

class TerminalsRepository
{
    public function __construct(
        private PDO $db,
        private Logger $logger,
    ) {}

    public function findById(string $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT t.*, (SELECT MAX(tx.created_at) FROM transactions tx WHERE tx.created_by_terminal_id = t.id) AS last_transaction_at
             FROM terminals t WHERE t.id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function findByDeviceId(string $deviceId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM terminals WHERE device_id = ? LIMIT 1');
        $stmt->execute([$deviceId]);
        return $stmt->fetch() ?: null;
    }

    public function findByTokenHash(string $sha256): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM terminals WHERE api_token_hash = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$sha256]);
        return $stmt->fetch() ?: null;
    }

    public function findActive(): array
    {
        return $this->db->query('SELECT * FROM terminals WHERE is_active = 1 ORDER BY created_at ASC')->fetchAll();
    }

    public function findAll(): array
    {
        return $this->db->query('SELECT * FROM terminals ORDER BY created_at DESC')->fetchAll();
    }

    public function create(array $data): array
    {
        $id = $data['id'] ?? $this->generateUuid();
        $now = date('Y-m-d H:i:s');

        $stmt = $this->db->prepare(
            'INSERT INTO terminals (id, name, device_id, api_token_hash, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            $data['name'],
            $data['device_id'],
            $data['api_token_hash'],
            ($data['is_active'] ?? true) ? 1 : 0,
            $now,
            $now,
        ]);

        $this->logger->info('Terminal created', ['id' => $id]);
        return $this->findById($id);
    }

    public function updateById(string $id, array $data): ?array
    {
        $allowed = ['name', 'device_id', 'api_token_hash', 'is_active', 'last_sync_at'];
        [$set, $values] = SafeQuery::buildUpdate($data, $allowed);
        $values[] = date('Y-m-d H:i:s');
        $values[] = $id;

        $stmt = $this->db->prepare("UPDATE terminals SET {$set}, updated_at = ? WHERE id = ?");
        $stmt->execute($values);

        $this->logger->info('Terminal updated', ['id' => $id]);
        return $this->findById($id);
    }

    public function updateLastSync(string $id): bool
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare('UPDATE terminals SET last_sync_at = ?, updated_at = ? WHERE id = ?');
        return $stmt->execute([$now, $now, $id]);
    }

    public function countActive(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM terminals WHERE is_active = 1')->fetchColumn();
    }

    public function listPaginated(int $limit, int $offset, ?bool $isActive = null): array
    {
        $where = [];
        $params = [];

        if ($isActive !== null) {
            $where[] = 'is_active = ?';
            $params[] = $isActive ? 1 : 0;
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM terminals {$whereClause}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $dataParams = array_merge($params, [$limit, $offset]);
        $stmt = $this->db->prepare(
            "SELECT t.*, (SELECT MAX(tx.created_at) FROM transactions tx WHERE tx.created_by_terminal_id = t.id) AS last_transaction_at
             FROM terminals t {$whereClause} ORDER BY t.created_at DESC LIMIT ? OFFSET ?"
        );
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
