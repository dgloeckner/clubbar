<?php

declare(strict_types=1);

namespace App\Modules\Settlements\Repositories;

use PDO;
use App\Modules\Settlements\Enums\SettlementMethod;
use App\Shared\Logging\Logger;
use App\Shared\Repository\SafeQuery;

class SettlementsRepository
{
    public function __construct(
        private PDO $db,
        private Logger $logger,
    ) {}

    public function findById(string $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT s.*, a.display_name as admin_display_name FROM settlements s LEFT JOIN admin_users a ON s.created_by_admin_id = a.id WHERE s.id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get the most recent settlement (non-cancelled)
     */
    public function getLatest(): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT s.*, a.display_name as admin_display_name
             FROM settlements s
             LEFT JOIN admin_users a ON s.created_by_admin_id = a.id
             WHERE s.is_cancelled = 0
             ORDER BY s.created_at DESC
             LIMIT 1'
        );
        $stmt->execute();
        return $stmt->fetch() ?: null;
    }

    public function findItemsBySettlementId(string $settlementId): array
    {
        $stmt = $this->db->prepare(
            'SELECT si.*, m.first_name, m.last_name, t.transaction_type, t.notes AS transaction_notes, t.product_id, t.occurred_at AS transaction_created_at, p.names AS product_names, p.price_cents AS product_price_cents
             FROM settlement_items si
             LEFT JOIN members m ON si.member_id = m.id
             LEFT JOIN transactions t ON si.transaction_id = t.id
             LEFT JOIN products p ON t.product_id = p.id
             WHERE si.settlement_id = ?
             ORDER BY m.last_name ASC, t.occurred_at ASC'
        );
        $stmt->execute([$settlementId]);
        return $stmt->fetchAll();
    }

    public function calculateMemberBalances(?string $fromDate = null, ?string $toDate = null): array
    {
        $where = ['NOT EXISTS (SELECT 1 FROM settlement_items si JOIN settlements s ON si.settlement_id = s.id WHERE si.transaction_id = t.id AND s.is_cancelled = 0)'];
        $params = [];

        if ($fromDate) { $where[] = 't.occurred_at >= ?'; $params[] = $fromDate; }
        if ($toDate) { $where[] = 't.occurred_at <= ?'; $params[] = $toDate . ' 23:59:59'; }

        $whereClause = 'WHERE ' . implode(' AND ', $where);
        $stmt = $this->db->prepare("SELECT t.member_id, SUM(t.amount_cents) as total FROM transactions t {$whereClause} GROUP BY t.member_id");
        $stmt->execute($params);

        $balances = [];
        foreach ($stmt->fetchAll() as $row) {
            $balances[$row['member_id']] = (int) $row['total'];
        }
        return $balances;
    }

    public function create(array $data): array
    {
        $id = $data['id'] ?? $this->generateUuid();
        $now = date('Y-m-d H:i:s');

        $stmt = $this->db->prepare(
            'INSERT INTO settlements (id, method, settlement_date, execution_date, period_start, period_end, sepa_message_id, total_amount_cents, member_count, is_cancelled, notes, created_by_admin_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            // A settlement is a direct debit unless the caller says otherwise —
            // the same default the column carries (#163).
            $data['method'] ?? SettlementMethod::DIRECT_DEBIT->value,
            $data['settlement_date'],
            $data['execution_date'],
            $data['period_start'] ?? null,
            $data['period_end'] ?? null,
            $data['sepa_message_id'] ?? null,
            (int) $data['total_amount_cents'],
            (int) $data['member_count'],
            0,
            $data['notes'] ?? null,
            $data['created_by_admin_id'] ?? null,
            $now,
            $now,
        ]);

        $this->logger->info('Settlement created', ['id' => $id]);
        return $this->findById($id);
    }

    public function createItem(array $data): void
    {
        // active_transaction_id mirrors transaction_id: a freshly created
        // settlement's claim on the transaction is live (#163). It is the
        // column later cleared/repointed by correction handling, since
        // transaction_id itself is no longer unique.
        $stmt = $this->db->prepare(
            'INSERT INTO settlement_items (settlement_id, transaction_id, active_transaction_id, member_id, amount_cents, end_to_end_id) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['settlement_id'],
            $data['transaction_id'],
            $data['transaction_id'],
            $data['member_id'],
            (int) $data['amount_cents'],
            $data['end_to_end_id'] ?? null,
        ]);
    }

    public function cancelSettlement(string $id, string $adminUserId): bool
    {
        $now = date('Y-m-d H:i:s');

        $this->db->prepare('DELETE FROM settlement_items WHERE settlement_id = ?')->execute([$id]);

        $stmt = $this->db->prepare('UPDATE settlements SET is_cancelled = 1, cancelled_at = ?, cancelled_by_admin_id = ?, updated_at = ? WHERE id = ?');
        $result = $stmt->execute([$now, $adminUserId, $now, $id]);

        $this->logger->info('Settlement cancelled', ['id' => $id]);
        return $result;
    }

    public function markExported(string $id): bool
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare('UPDATE settlements SET exported_at = ?, updated_at = ? WHERE id = ?');
        return $stmt->execute([$now, $now, $id]);
    }

    public function listPaginated(int $limit, int $offset, ?string $status = null, string $sortKey = 'created_at', string $sortOrder = 'desc', ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $where = [];
        $params = [];

        if ($status === 'active') { $where[] = 's.is_cancelled = 0'; }
        elseif ($status === 'cancelled') { $where[] = 's.is_cancelled = 1'; }

        if ($dateFrom) {
            $where[] = 's.created_at >= ?';
            $params[] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo) {
            $where[] = 's.created_at <= ?';
            $params[] = $dateTo . ' 23:59:59';
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $dir = SafeQuery::direction($sortOrder);
        $sortCol = $sortKey === 'created_at' ? 's.created_at' : 's.created_at';

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM settlements s {$whereClause}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $dataParams = array_merge($params, [$limit, $offset]);
        $stmt = $this->db->prepare(
            "SELECT s.*, a.display_name as admin_display_name,
                (SELECT COUNT(*) FROM settlement_items si WHERE si.settlement_id = s.id) as transaction_count,
                (SELECT MIN(t.occurred_at) FROM settlement_items si JOIN transactions t ON si.transaction_id = t.id WHERE si.settlement_id = s.id) as transaction_date_min,
                (SELECT MAX(t.occurred_at) FROM settlement_items si JOIN transactions t ON si.transaction_id = t.id WHERE si.settlement_id = s.id) as transaction_date_max
             FROM settlements s LEFT JOIN admin_users a ON s.created_by_admin_id = a.id {$whereClause} ORDER BY {$sortCol} {$dir} LIMIT ? OFFSET ?"
        );
        $stmt->execute($dataParams);

        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }

    public function getNextSepaMessageId(): string
    {
        $uuid = $this->generateUuid();
        return 'SEPA-' . substr(str_replace('-', '', $uuid), 0, 12);
    }

    public function hasConflicts(array $transactionIds): array
    {
        if (empty($transactionIds)) return [];

        [$placeholders, $params] = SafeQuery::inClause($transactionIds, 'string');
        $stmt = $this->db->prepare(
            "SELECT si.transaction_id, s.settlement_date FROM settlement_items si JOIN settlements s ON si.settlement_id = s.id WHERE si.transaction_id IN ({$placeholders}) AND s.is_cancelled = 0"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function count(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM settlements WHERE is_cancelled = 0')->fetchColumn();
    }

    public function countPending(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM settlements WHERE is_cancelled = 0 AND exported_at IS NULL')->fetchColumn();
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
