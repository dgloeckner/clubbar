<?php

declare(strict_types=1);

namespace App\Modules\Products\Repositories;

use PDO;
use App\Shared\Logging\Logger;
use App\Shared\Repository\SafeQuery;

class CategoriesRepository
{
    public function __construct(
        private PDO $db,
        private Logger $logger,
    ) {}

    public function findById(string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM categories WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function findAll(): array
    {
        return $this->db->query('SELECT * FROM categories ORDER BY display_order ASC')->fetchAll();
    }

    public function findModifiedSince(int $sinceTimestamp): array
    {
        // Convert milliseconds to seconds for date() function
        $sinceSeconds = (int) ($sinceTimestamp / 1000);
        $sinceDate = date('Y-m-d H:i:s', $sinceSeconds);

        // Include both updated and deleted items (tombstones)
        // This enables the terminal to remove deleted items from local cache
        $stmt = $this->db->prepare(
            'SELECT * FROM categories
             WHERE updated_at >= ? OR (deleted_at >= ? AND deleted_at IS NOT NULL)
             ORDER BY COALESCE(updated_at, deleted_at) ASC'
        );
        $stmt->execute([$sinceDate, $sinceDate]);
        return $stmt->fetchAll();
    }


    public function findActive(): array
    {
        return $this->db->query('SELECT * FROM categories WHERE is_active = 1 ORDER BY display_order ASC')->fetchAll();
    }

    public function getWithProductCount(): array
    {
        return $this->db->query(
            'SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS product_count FROM categories c ORDER BY c.display_order ASC'
        )->fetchAll();
    }

    public function hasProducts(string $categoryId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM products WHERE category_id = ? LIMIT 1');
        $stmt->execute([$categoryId]);
        return (bool) $stmt->fetch();
    }

    public function getNextDisplayOrder(): int
    {
        $max = $this->db->query('SELECT MAX(display_order) FROM categories')->fetchColumn();
        return ($max !== null && $max !== false) ? ((int) $max + 1) : 0;
    }

    public function create(array $data): array
    {
        $id = $data['id'] ?? $this->generateUuid();
        $now = date('Y-m-d H:i:s');
        $names = is_array($data['names']) ? json_encode($data['names']) : $data['names'];

        $stmt = $this->db->prepare(
            'INSERT INTO categories (id, names, display_order, is_active, icon_name, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            $names,
            $data['display_order'] ?? $this->getNextDisplayOrder(),
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
            $col = SafeQuery::column($key, ['names', 'display_order', 'is_active', 'icon_name']);
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

    public function deleteById(string $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM categories WHERE id = ?');
        $result = $stmt->execute([$id]);
        $this->logger->info('Category deleted', ['id' => $id]);
        return $result && $stmt->rowCount() > 0;
    }

    public function reorder(array $categoryIds): void
    {
        $stmt = $this->db->prepare('UPDATE categories SET display_order = ?, updated_at = ? WHERE id = ?');
        $now = date('Y-m-d H:i:s');
        foreach ($categoryIds as $index => $categoryId) {
            $stmt->execute([$index, $now, $categoryId]);
        }
    }

    public function count(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM categories')->fetchColumn();
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
