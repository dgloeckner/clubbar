<?php

declare(strict_types=1);

namespace App\Modules\Transactions\Repositories;

use PDO;
use App\Shared\Logging\Logger;
use App\Shared\Repository\SafeQuery;

class TransactionsRepository
{
    public function __construct(
        private PDO $db,
        private Logger $logger,
    ) {}

    public function findById(string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM transactions WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function insertTransaction(array $data): ?array
    {
        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO transactions (id, member_id, product_id, amount_cents, transaction_type, notes, related_transaction_id, created_by_terminal_id, created_by_admin_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['id'],
            $data['member_id'],
            $data['product_id'] ?? null,
            (int) $data['amount_cents'],
            $data['transaction_type'] ?? 'purchase',
            $data['notes'] ?? null,
            $data['related_transaction_id'] ?? null,
            $data['created_by_terminal_id'] ?? null,
            $data['created_by_admin_id'] ?? null,
            $data['created_at'] ?? date('Y-m-d H:i:s'),
        ]);

        if ($stmt->rowCount() === 0) {
            return null; // duplicate
        }

        $this->logger->info('Transaction created', ['id' => $data['id']]);
        return $this->findById($data['id']);
    }

    public function getMemberBalance(string $memberId): int
    {
        $stmt = $this->db->prepare('SELECT COALESCE(SUM(amount_cents), 0) FROM transactions WHERE member_id = ?');
        $stmt->execute([$memberId]);
        return (int) $stmt->fetchColumn();
    }

    public function findByMemberId(string $memberId, int $limit = 50, int $offset = 0, ?string $type = null, ?string $since = null): array
    {
        $where = ['t.member_id = ?'];
        $params = [$memberId];

        if ($type) {
            $where[] = 't.transaction_type = ?';
            $params[] = $type;
        }
        if ($since) {
            $where[] = 't.created_at >= ?';
            $params[] = $since;
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $this->db->prepare(
            "SELECT t.*, p.names as product_names FROM transactions t LEFT JOIN products p ON t.product_id = p.id {$whereClause} ORDER BY t.created_at DESC LIMIT ? OFFSET ?"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function listPaginated(int $limit, int $offset, array $filters = [], string $sortKey = 'created_at', string $sortOrder = 'desc'): array
    {
        $where = [];
        $params = [];

        if (isset($filters['type']) && $filters['type'] !== 'all') {
            $where[] = 't.transaction_type = ?';
            $params[] = $filters['type'];
        }
        if (isset($filters['member_id'])) {
            $where[] = 't.member_id = ?';
            $params[] = $filters['member_id'];
        }
        if (isset($filters['date_from'])) {
            $where[] = 't.created_at >= ?';
            $params[] = $filters['date_from'];
        }
        if (isset($filters['date_to'])) {
            $where[] = 't.created_at <= ?';
            $params[] = $filters['date_to'] . ' 23:59:59';
        }
        if (isset($filters['search'])) {
            $escaped = SafeQuery::escapeLike($filters['search']);
            $where[] = "(CONCAT(m.first_name, ' ', m.last_name) LIKE ? OR t.notes LIKE ?)";
            $params = array_merge($params, ["%{$escaped}%", "%{$escaped}%"]);
        }
        if (isset($filters['settlement_status'])) {
            if ($filters['settlement_status'] === 'unsettled') {
                $where[] = 'NOT EXISTS (SELECT 1 FROM settlement_items si JOIN settlements s ON si.settlement_id = s.id WHERE si.transaction_id = t.id AND s.is_cancelled = 0)';
            } elseif ($filters['settlement_status'] === 'settled') {
                $where[] = 'EXISTS (SELECT 1 FROM settlement_items si JOIN settlements s ON si.settlement_id = s.id WHERE si.transaction_id = t.id AND s.is_cancelled = 0)';
            }
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $sortMap = ['created_at' => 't.created_at', 'amount' => 't.amount_cents', 'type' => 't.transaction_type', 'member_name' => 'm.last_name', 'member' => 'm.last_name'];
        $sortCol = $sortMap[$sortKey] ?? 't.created_at';
        $dir = SafeQuery::direction($sortOrder);

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM transactions t LEFT JOIN members m ON t.member_id = m.id {$whereClause}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $dataParams = array_merge($params, [$limit, $offset]);
        $stmt = $this->db->prepare(
            "SELECT t.*, CONCAT(m.first_name, ' ', m.last_name) as member_name, m.first_name, m.last_name, m.email, p.names as product_names, (SELECT s.settlement_date FROM settlement_items si JOIN settlements s ON si.settlement_id = s.id WHERE si.transaction_id = t.id AND s.is_cancelled = 0 LIMIT 1) as settlement_date FROM transactions t LEFT JOIN members m ON t.member_id = m.id LEFT JOIN products p ON t.product_id = p.id {$whereClause} ORDER BY {$sortCol} {$dir} LIMIT ? OFFSET ?"
        );
        $stmt->execute($dataParams);

        $items = $stmt->fetchAll();
        foreach ($items as &$item) {
            $item['type'] = $item['transaction_type'] ?? null;
            $item['description'] = $item['notes'] ?? null;
        }
        unset($item);

        return ['items' => $items, 'total' => $total];
    }

    public function findUnsettledByIds(array $transactionIds): array
    {
        if (empty($transactionIds)) return [];

        [$placeholders, $params] = SafeQuery::inClause($transactionIds, 'string');
        $stmt = $this->db->prepare(
            "SELECT t.* FROM transactions t WHERE t.id IN ({$placeholders}) AND NOT EXISTS (SELECT 1 FROM settlement_items si JOIN settlements s ON si.settlement_id = s.id WHERE si.transaction_id = t.id AND s.is_cancelled = 0)"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function count(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM transactions')->fetchColumn();
    }

    public function countRecentTransactions(int $days = 30): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM transactions WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)');
        $stmt->execute([$days]);
        return (int) $stmt->fetchColumn();
    }

    public function sumRecentAmountCents(int $days = 30): int
    {
        $stmt = $this->db->prepare('SELECT COALESCE(SUM(amount_cents), 0) FROM transactions WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)');
        $stmt->execute([$days]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Calculate total amount of unsettled transactions (outstanding balance)
     */
    public function sumUnsettledAmountCents(): int
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(SUM(t.amount_cents), 0)
             FROM transactions t
             WHERE NOT EXISTS (
                 SELECT 1 FROM settlement_items si
                 JOIN settlements s ON si.settlement_id = s.id
                 WHERE si.transaction_id = t.id AND s.is_cancelled = 0
             )'
        );
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }
}
