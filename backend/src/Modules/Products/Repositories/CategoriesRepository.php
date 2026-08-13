<?php

declare(strict_types=1);

namespace App\Modules\Products\Repositories;

use App\Shared\Utils\Uuid;
use PDO;
use App\Shared\Logging\Logger;
use App\Shared\Repository\SafeQuery;
use App\Shared\Sync\SyncCursor;

class CategoriesRepository
{
    public function __construct(
        private PDO $db,
        private Logger $logger,
    ) {}

    public function findById(string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM categories WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function findModifiedSince(int $sinceTimestamp): array
    {
        $sinceDate = SyncCursor::lowerBound($sinceTimestamp);

        // Include both updated and deleted items (tombstones)
        // This enables the terminal to remove deleted items from local cache
        // The bound is inclusive (>=): the column has second precision, so a
        // strict > loses every category written later in the cursor's own
        // second, and loses it for good (#84).
        $stmt = $this->db->prepare(
            'SELECT * FROM categories
             WHERE updated_at >= ? OR (deleted_at >= ? AND deleted_at IS NOT NULL)
             ORDER BY COALESCE(updated_at, deleted_at) ASC'
        );
        $stmt->execute([$sinceDate, $sinceDate]);
        return $stmt->fetchAll();
    }

    public function getWithProductCount(): array
    {
        return $this->db->query(
            'SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id AND p.deleted_at IS NULL) AS product_count
             FROM categories c WHERE c.deleted_at IS NULL ORDER BY c.created_at ASC'
        )->fetchAll();
    }

    public function hasProducts(string $categoryId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM products WHERE category_id = ? AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([$categoryId]);
        return (bool) $stmt->fetch();
    }

    public function create(array $data): array
    {
        $id = $data['id'] ?? Uuid::v4();
        $now = date('Y-m-d H:i:s');
        $names = is_array($data['names']) ? json_encode($data['names']) : $data['names'];

        $stmt = $this->db->prepare(
            'INSERT INTO categories (id, names, is_active, icon_name, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            $names,
            ($data['is_active'] ?? true) ? 1 : 0,
            $data['icon_name'] ?? null,
            $now,
            $now,
        ]);

        $this->logger->info('Category created', ['id' => $id]);
        return $this->findById($id);
    }

    public function updateById(string $id, array $data): ?array
    {
        $fields = [];
        $values = [];

        foreach ($data as $key => $value) {
            $col = SafeQuery::column($key, ['names', 'is_active', 'icon_name', 'deleted_at', 'deleted_by_admin_id']);
            if ($key === 'names' && is_array($value)) {
                $value = json_encode($value);
            }
            if ($key === 'is_active') {
                $value = $value ? 1 : 0;
            }
            $fields[] = "{$col} = ?";
            $values[] = $value;
        }

        $values[] = date('Y-m-d H:i:s');
        $values[] = $id;
        $set = implode(', ', $fields);

        $stmt = $this->db->prepare("UPDATE categories SET {$set}, updated_at = ? WHERE id = ?");
        $stmt->execute($values);

        $this->logger->info('Category updated', ['id' => $id]);
        return $this->findById($id);
    }

}
