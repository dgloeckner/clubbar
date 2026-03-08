<?php

declare(strict_types=1);

namespace App\Modules\Members\Repositories;

use PDO;
use App\Shared\Logging\Logger;
use App\Shared\Repository\SafeQuery;

class MembersRepository
{
    public function __construct(
        private PDO $db,
        private Logger $logger,
    ) {}

    public function findById(string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM members WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function findByIdIncludingDeleted(string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM members WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function findAll(): array
    {
        return $this->db->query('SELECT * FROM members ORDER BY created_at DESC')->fetchAll();
    }

    public function findModifiedSince(int $sinceTimestamp): array
    {
        // Convert milliseconds to seconds for date() function
        $sinceSeconds = (int) ($sinceTimestamp / 1000);
        $sinceDate = date('Y-m-d H:i:s', $sinceSeconds);

        // Include both updated and deleted items (tombstones)
        // This enables the terminal to remove deleted items from local cache
        // Use > (not >=) to avoid re-syncing items at exactly the cursor timestamp
        $stmt = $this->db->prepare(
            'SELECT * FROM members
             WHERE updated_at > ? OR (deleted_at > ? AND deleted_at IS NOT NULL)
             ORDER BY COALESCE(updated_at, deleted_at) ASC'
        );
        $stmt->execute([$sinceDate, $sinceDate]);
        return $stmt->fetchAll();
    }

    public function create(array $data): array
    {
        $id = $data['id'] ?? $this->generateUuid();
        $now = date('Y-m-d H:i:s');

        $stmt = $this->db->prepare(
            'INSERT INTO members (id, card_uid, first_name, last_name, email, phone, preferred_language, is_active, iban, account_holder_name, mandate_reference, mandate_signed_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $iban = $data['iban'] ?? null;
        // Per data model: mandate_reference defaults to UUID without hyphens when IBAN is present,
        // but only when mandate_reference is not explicitly provided (key missing from data).
        // If key is present but null, respect the explicit null (no auto-generation).
        $mandateReference = array_key_exists('mandate_reference', $data)
            ? ($data['mandate_reference'] ?: null)
            : ($iban !== null ? str_replace('-', '', $id) : null);

        $stmt->execute([
            $id,
            $data['card_uid'] ?? null,
            $data['first_name'],
            $data['last_name'],
            $data['email'],
            $data['phone'] ?? null,
            $data['preferred_language'] ?? 'de',
            $data['is_active'] ?? true ? 1 : 0,
            $iban,
            $data['account_holder_name'] ?? null,
            $mandateReference,
            $data['mandate_signed_at'] ?? null,
            $now,
            $now,
        ]);

        $this->logger->info('Member created', ['id' => $id]);
        return $this->findById($id);
    }

    public function updateById(string $id, array $data): ?array
    {
        $allowed = ['card_uid', 'first_name', 'last_name', 'email', 'phone', 'preferred_language', 'is_active', 'iban', 'account_holder_name', 'mandate_reference', 'mandate_signed_at', 'deleted_at', 'deleted_by_admin_id'];
        [$set, $values] = SafeQuery::buildUpdate($data, $allowed);
        $values[] = date('Y-m-d H:i:s');
        $values[] = $id;

        $stmt = $this->db->prepare("UPDATE members SET {$set}, updated_at = ? WHERE id = ?");
        $stmt->execute($values);

        $this->logger->info('Member updated', ['id' => $id]);
        return $this->findById($id);
    }

    public function deleteById(string $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM members WHERE id = ?');
        $result = $stmt->execute([$id]);
        $this->logger->info('Member deleted', ['id' => $id]);
        return $result && $stmt->rowCount() > 0;
    }

    public function count(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM members')->fetchColumn();
    }

    public function countActive(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM members WHERE is_active = 1')->fetchColumn();
    }

    public function exists(string $id): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM members WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return (bool) $stmt->fetch();
    }

    public function anonymize(string $id): bool
    {
        $now = date('Y-m-d H:i:s');
        // card_uid is VARCHAR(20), so use ANON- + 15 chars of UUID = 20 chars max
        $anonCardUid = 'ANON-' . substr(str_replace('-', '', $this->generateUuid()), 0, 15);
        $stmt = $this->db->prepare(
            'UPDATE members SET first_name = NULL, last_name = NULL, email = NULL, phone = NULL, iban = NULL, account_holder_name = NULL, mandate_reference = NULL, card_uid = ?, is_active = 0, deleted_at = ?, updated_at = ? WHERE id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$anonCardUid, $now, $now, $id]);
        return $stmt->rowCount() > 0;
    }

    public function listPaginated(int $limit, int $offset, array $filters = [], string $sortKey = 'created_at', string $sortOrder = 'desc', ?string $search = null): array
    {
        $where = ['deleted_at IS NULL'];
        $params = [];

        if (isset($filters['is_active'])) {
            $where[] = 'is_active = ?';
            $params[] = $filters['is_active'] ? 1 : 0;
        }
        if (isset($filters['language'])) {
            $where[] = 'preferred_language = ?';
            $params[] = $filters['language'];
        }
        // Card UID filter
        if (isset($filters['has_card_uid'])) {
            if ($filters['has_card_uid']) {
                $where[] = 'card_uid IS NOT NULL';
            } else {
                $where[] = 'card_uid IS NULL';
            }
        }
        // SEPA status filter (is_sepa_valid = !empty(iban) && !empty(mandate_reference))
        if (isset($filters['sepa_status'])) {
            if ($filters['sepa_status'] === 'valid') {
                $where[] = "(iban IS NOT NULL AND iban != '' AND mandate_reference IS NOT NULL AND mandate_reference != '')";
            } else {
                $where[] = "(iban IS NULL OR iban = '' OR mandate_reference IS NULL OR mandate_reference = '')";
            }
        }
        if ($search) {
            $escaped = SafeQuery::escapeLike($search);
            $where[] = "(CONCAT(first_name, ' ', last_name) LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR email LIKE ?)";
            $params = array_merge($params, ["%{$escaped}%", "%{$escaped}%", "%{$escaped}%", "%{$escaped}%"]);
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $columnMap = ['id' => 'id', 'first_name' => 'first_name', 'last_name' => 'last_name', 'balance' => 'balance_cents', 'created_at' => 'created_at'];
        $col = SafeQuery::column($sortKey, array_keys($columnMap));
        $sortColumn = $columnMap[$col];
        $dir = SafeQuery::direction($sortOrder);

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM members {$whereClause}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $dataParams = array_merge($params, [$limit, $offset]);
        $stmt = $this->db->prepare("SELECT * FROM members {$whereClause} ORDER BY {$sortColumn} {$dir} LIMIT ? OFFSET ?");
        $stmt->execute($dataParams);
        $items = $stmt->fetchAll();

        return ['items' => $items, 'total' => $total];
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
