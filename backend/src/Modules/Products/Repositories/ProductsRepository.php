<?php

declare(strict_types=1);

namespace App\Modules\Products\Repositories;

use App\Shared\Utils\Uuid;
use PDO;
use App\Shared\Logging\Logger;
use App\Shared\Repository\SafeQuery;
use App\Shared\Sync\SyncCursor;

class ProductsRepository
{
    public function __construct(
        private PDO $db,
        private Logger $logger,
    ) {}

    public function findById(string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM products WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function findModifiedSince(int $sinceTimestamp): array
    {
        $sinceDate = SyncCursor::lowerBound($sinceTimestamp);

        // Include both updated and deleted items (tombstones)
        // This enables the terminal to remove deleted items from local cache
        // The bound is inclusive (>=): the column has second precision, so a
        // strict > loses every price written later in the cursor's own second,
        // and loses it for good — the terminal keeps selling at the stale price
        // until some unrelated edit touches the row again (#84).
        $stmt = $this->db->prepare(
            'SELECT * FROM products
             WHERE updated_at >= ? OR (deleted_at >= ? AND deleted_at IS NOT NULL)
             ORDER BY COALESCE(updated_at, deleted_at) ASC'
        );
        $stmt->execute([$sinceDate, $sinceDate]);
        return $stmt->fetchAll();
    }

    public function create(array $data): array
    {
        $id = $data['id'] ?? Uuid::v4();
        $now = date('Y-m-d H:i:s');
        $names = is_array($data['names']) ? json_encode($data['names']) : $data['names'];
        $descriptions = is_array($data['descriptions'] ?? []) ? json_encode($data['descriptions'] ?? []) : ($data['descriptions'] ?? '{}');

        $stmt = $this->db->prepare(
            'INSERT INTO products (id, category_id, names, descriptions, price_cents, is_active, icon_name, requires_dispenser, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            $data['category_id'],
            $names,
            $descriptions,
            (int) $data['price_cents'],
            ($data['is_active'] ?? true) ? 1 : 0,
            $data['icon_name'] ?? null,
            ($data['requires_dispenser'] ?? false) ? 1 : 0,
            $now,
            $now,
        ]);

        $this->logger->info('Product created', ['id' => $id]);
        return $this->findById($id);
    }

    public function updateById(string $id, array $data): ?array
    {
        $fields = [];
        $values = [];

        $allowed = ['category_id', 'names', 'descriptions', 'price_cents', 'is_active', 'icon_name', 'requires_dispenser', 'deleted_at', 'deleted_by_admin_id'];
        foreach ($data as $key => $value) {
            if (!in_array($key, $allowed, true)) continue;
            if (($key === 'names' || $key === 'descriptions') && is_array($value)) {
                $value = json_encode($value);
            }
            if ($key === 'is_active' || $key === 'requires_dispenser') {
                $value = $value ? 1 : 0;
            }
            $fields[] = "{$key} = ?";
            $values[] = $value;
        }

        if (empty($fields)) return $this->findById($id);

        $values[] = date('Y-m-d H:i:s');
        $values[] = $id;
        $set = implode(', ', $fields);

        $stmt = $this->db->prepare("UPDATE products SET {$set}, updated_at = ? WHERE id = ?");
        $stmt->execute($values);

        $this->logger->info('Product updated', ['id' => $id]);
        return $this->findById($id);
    }

    public function listPaginated(int $limit, int $offset, array $filters = [], string $sortBy = 'created_at', string $sortOrder = 'desc'): array
    {
        $where = [];
        $params = [];

        // Always exclude soft-deleted products
        $where[] = 'p.deleted_at IS NULL';

        if (isset($filters['status'])) {
            if ($filters['status'] === 'active') { $where[] = 'p.is_active = 1'; }
            elseif ($filters['status'] === 'inactive') { $where[] = 'p.is_active = 0'; }
        }
        if (isset($filters['category_id'])) {
            $where[] = 'p.category_id = ?';
            $params[] = $filters['category_id'];
        }
        if (isset($filters['search'])) {
            // Use LOWER() for case-insensitive search because the names column
            // uses utf8mb4_bin collation (required for JSON storage).
            $where[] = "JSON_SEARCH(LOWER(p.names), 'one', ?) IS NOT NULL";
            $params[] = '%' . mb_strtolower($filters['search']) . '%';
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $sortMap = ['name' => "JSON_UNQUOTE(JSON_EXTRACT(p.names, '$.de'))", 'price' => 'p.price_cents', 'category' => "JSON_UNQUOTE(JSON_EXTRACT(c.names, '$.de'))", 'created_at' => 'p.created_at'];
        $sortCol = $sortMap[$sortBy] ?? 'p.created_at';
        $dir = SafeQuery::direction($sortOrder);

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM products p {$whereClause}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $dataParams = array_merge($params, [$limit, $offset]);
        $stmt = $this->db->prepare(
            "SELECT p.*, c.names as category_names FROM products p LEFT JOIN categories c ON p.category_id = c.id {$whereClause} ORDER BY {$sortCol} {$dir} LIMIT ? OFFSET ?"
        );
        $stmt->execute($dataParams);

        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }
}
