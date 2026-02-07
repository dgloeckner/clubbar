<?php

declare(strict_types=1);

namespace App\Modules\Products\Repositories;

use PDO;
use App\Shared\Logging\Logger;
use App\Shared\Repository\SafeQuery;

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

    public function findAll(): array
    {
        return $this->db->query('SELECT * FROM products ORDER BY created_at ASC')->fetchAll();
    }

    public function findModifiedSince(int $sinceTimestamp): array
    {
        // Convert milliseconds to seconds for date() function
        $sinceSeconds = (int) ($sinceTimestamp / 1000);
        $sinceDate = date('Y-m-d H:i:s', $sinceSeconds);

        // Include both updated and deleted items (tombstones)
        // This enables the terminal to remove deleted items from local cache
        $stmt = $this->db->prepare(
            'SELECT * FROM products
             WHERE updated_at >= ? OR (deleted_at >= ? AND deleted_at IS NOT NULL)
             ORDER BY COALESCE(updated_at, deleted_at) ASC'
        );
        $stmt->execute([$sinceDate, $sinceDate]);
        return $stmt->fetchAll();
    }


    public function findActive(): array
    {
        return $this->db->query(
            'SELECT p.* FROM products p INNER JOIN categories c ON p.category_id = c.id WHERE p.is_active = 1 AND c.is_active = 1 ORDER BY p.created_at ASC'
        )->fetchAll();
    }

    public function findByCategory(string $categoryId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM products WHERE category_id = ? ORDER BY created_at ASC');
        $stmt->execute([$categoryId]);
        return $stmt->fetchAll();
    }

    public function create(array $data): array
    {
        $id = $data['id'] ?? $this->generateUuid();
        $now = date('Y-m-d H:i:s');
        $names = is_array($data['names']) ? json_encode($data['names']) : $data['names'];
        $descriptions = is_array($data['descriptions'] ?? []) ? json_encode($data['descriptions'] ?? []) : ($data['descriptions'] ?? '{}');

        $stmt = $this->db->prepare(
            'INSERT INTO products (id, category_id, names, descriptions, price_cents, is_active, icon_name, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            $data['category_id'],
            $names,
            $descriptions,
            (int) $data['price_cents'],
            ($data['is_active'] ?? true) ? 1 : 0,
            $data['icon_name'] ?? null,
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

        $allowed = ['category_id', 'names', 'descriptions', 'price_cents', 'is_active', 'icon_name', 'deleted_at', 'deleted_by_admin_id'];
        foreach ($data as $key => $value) {
            if (!in_array($key, $allowed, true)) continue;
            if (($key === 'names' || $key === 'descriptions') && is_array($value)) {
                $value = json_encode($value);
            }
            if ($key === 'is_active') {
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

    public function deleteById(string $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM products WHERE id = ?');
        $result = $stmt->execute([$id]);
        $this->logger->info('Product deleted', ['id' => $id]);
        return $result && $stmt->rowCount() > 0;
    }

    public function listPaginated(int $limit, int $offset, array $filters = [], string $sortBy = 'created_at', string $sortOrder = 'desc'): array
    {
        $where = [];
        $params = [];

        if (isset($filters['status'])) {
            if ($filters['status'] === 'active') { $where[] = 'p.is_active = 1'; }
            elseif ($filters['status'] === 'inactive') { $where[] = 'p.is_active = 0'; }
        }
        if (isset($filters['category_id'])) {
            $where[] = 'p.category_id = ?';
            $params[] = $filters['category_id'];
        }
        if (isset($filters['search'])) {
            // For JSON_SEARCH, use unescaped LIKE pattern since JSON_SEARCH
            // handles wildcards internally. Don't use escapeLike() here as it
            // escapes _ which is valid in user input we want to match literally.
            $where[] = "JSON_SEARCH(p.names, 'one', ?) IS NOT NULL";
            $params[] = '%' . $filters['search'] . '%';
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

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

    public function count(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM products')->fetchColumn();
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
