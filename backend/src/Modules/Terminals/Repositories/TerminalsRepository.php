<?php

declare(strict_types=1);

namespace App\Modules\Terminals\Repositories;

use App\Shared\Utils\Uuid;
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
            'SELECT t.*, (SELECT MAX(tx.occurred_at) FROM transactions tx WHERE tx.created_by_terminal_id = t.id) AS last_transaction_at
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

    /**
     * The authentication lookup (#106).
     *
     * Expiry is enforced here rather than in the middleware so that no caller
     * can authenticate a terminal without it, and the check is fail-closed: a
     * row that carries a token hash but no `token_expires_at` does not
     * authenticate. Every path that issues a token sets the column, so the only
     * rows that can be in that state are ones nobody meant to be usable.
     */
    public function findByTokenHash(string $sha256): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM terminals
              WHERE api_token_hash = ?
                AND is_active = 1
                AND token_expires_at IS NOT NULL
                AND token_expires_at > NOW()
              LIMIT 1'
        );
        $stmt->execute([$sha256]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Counterpart of findByTokenHash() for a token that once was valid.
     *
     * Used only to tell an expired token apart from an unknown one in the 401,
     * so the operator of a terminal that stopped syncing learns to rotate it
     * instead of hunting a typo. It never returns a terminal that authenticates.
     */
    public function findExpiredByTokenHash(string $sha256): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM terminals
              WHERE api_token_hash = ?
                AND is_active = 1
                AND (token_expires_at IS NULL OR token_expires_at <= NOW())
              LIMIT 1'
        );
        $stmt->execute([$sha256]);
        return $stmt->fetch() ?: null;
    }

    public function findAll(): array
    {
        return $this->db->query('SELECT * FROM terminals ORDER BY created_at DESC')->fetchAll();
    }

    public function create(array $data): array
    {
        $id = $data['id'] ?? Uuid::v4();
        $now = date('Y-m-d H:i:s');
        $ttlDays = self::ttlDays($data['token_ttl_days'] ?? null);

        $stmt = $this->db->prepare(
            "INSERT INTO terminals (id, name, device_id, api_token_hash, token_issued_at, token_expires_at, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, NOW(), NOW() + INTERVAL {$ttlDays} DAY, ?, ?, ?)"
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

    /**
     * Issue a new token for an existing terminal and restart its lifetime (#106).
     *
     * Both the issue time and the expiry come from the database clock, the same
     * one findByTokenHash() compares against, so a skew between the PHP and
     * MariaDB hosts cannot shorten or extend a token's life.
     */
    public function rotateToken(string $id, string $sha256, int $ttlDays): ?array
    {
        $days = self::ttlDays($ttlDays);

        $stmt = $this->db->prepare(
            "UPDATE terminals
                SET api_token_hash   = ?,
                    token_issued_at  = NOW(),
                    token_expires_at = NOW() + INTERVAL {$days} DAY,
                    last_sync_at     = NULL,
                    updated_at       = ?
              WHERE id = ?"
        );
        $stmt->execute([$sha256, date('Y-m-d H:i:s'), $id]);

        $this->logger->info('Terminal token rotated', ['id' => $id, 'ttl_days' => $days]);
        return $this->findById($id);
    }

    public function updateById(string $id, array $data): ?array
    {
        $allowed = ['name', 'device_id', 'api_token_hash', 'is_active', 'last_sync_at', 'token_issued_at', 'token_expires_at'];
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
            "SELECT t.*, (SELECT MAX(tx.occurred_at) FROM transactions tx WHERE tx.created_by_terminal_id = t.id) AS last_transaction_at
             FROM terminals t {$whereClause} ORDER BY t.created_at DESC LIMIT ? OFFSET ?"
        );
        $stmt->execute($dataParams);

        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }

    /**
     * Sanitise a token lifetime before it is interpolated into an INTERVAL.
     *
     * MariaDB rejects a placeholder as the quantity of an INTERVAL, so the
     * value is inlined — casting to a positive int is what keeps that safe.
     * A missing or non-positive lifetime falls back to the AppConfig default
     * rather than producing a token that is born expired.
     */
    private static function ttlDays(mixed $ttlDays): int
    {
        $days = (int) $ttlDays;

        return $days > 0 ? $days : 90;
    }
}
