<?php

declare(strict_types=1);

namespace App\Modules\Transactions\Repositories;

use PDO;
use App\Modules\Transactions\Exceptions\TransactionAlreadyStornoedException;
use App\Modules\Transactions\Exceptions\TransactionNotStorableException;
use App\Shared\Logging\Logger;
use App\Shared\Repository\SafeQuery;
use App\Shared\Repository\UnsettledTransactions;
use App\Shared\Utils\DateFormatter;

class TransactionsRepository
{
    public function __construct(
        private PDO $db,
        private Logger $logger,
    ) {}

    public function findById(string $id): ?array
    {
        // occurred_at is the terminal-owned sale time; the API still calls it created_at until #172 renames the contract.
        $stmt = $this->db->prepare('SELECT *, occurred_at AS created_at FROM transactions WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Store one transaction, or report that its id was already stored.
     *
     * Returns the stored row, or `null` when a row with this id already
     * exists — the idempotent replay of ADR-0004, which is the *only* failure
     * this method absorbs. `INSERT IGNORE` used to absorb every ignorable
     * error alongside it (#82): a dangling foreign key or an out-of-range
     * value produced the same "zero rows affected" as a replay, the caller
     * read it as "already had it", and the sale was reported accepted while no
     * record of it existed anywhere.
     *
     * @throws TransactionNotStorableException when the database refuses the row
     * @throws \PDOException on any transient database failure
     */
    public function insertTransaction(array $data): ?array
    {
        // Terminals still send the field as created_at; it is written to occurred_at
        // (the sale time), while received_at is stamped by the database default.
        $stmt = $this->db->prepare(
            'INSERT INTO transactions (id, member_id, product_id, amount_cents, transaction_type, notes, related_transaction_id, created_by_terminal_id, created_by_admin_id, occurred_at, dispenser_tx_id, dispenser_requested, dispenser_actual) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        try {
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
                DateFormatter::toMysqlDateTime($data['created_at'] ?? null) ?? date('Y-m-d H:i:s'),
                $data['dispenser_tx_id'] ?? null,
                $data['dispenser_requested'] ?? null,
                $data['dispenser_actual'] ?? null,
            ]);
        } catch (\PDOException $e) {
            if (self::isDuplicateKey($e)) {
                return null; // Already stored — the terminal is replaying the batch
            }
            if (self::isRowRefused($e)) {
                $this->logger->error('Transaction refused by the database', [
                    'id' => $data['id'] ?? null,
                    'sqlstate' => $e->errorInfo[0] ?? null,
                    'driver_code' => $e->errorInfo[1] ?? null,
                    'driver_message' => $e->errorInfo[2] ?? null,
                ]);
                throw new TransactionNotStorableException(
                    'Transaction cannot be stored: the database refused the row',
                    0,
                    $e,
                );
            }
            throw $e; // Transient — the caller should retry the whole batch
        }

        $this->logger->info('Transaction created', ['id' => $data['id']]);
        return $this->findById($data['id']);
    }

    /**
     * Errno 1062 is a duplicate primary or unique key and nothing else.
     * SQLSTATE 23000 alone is not enough — MySQL reports foreign-key failures
     * (1452), NOT NULL failures (1048) and check-constraint failures (3819)
     * under the same class, and those are the rows that must never be mistaken
     * for a replay.
     */
    private static function isDuplicateKey(\PDOException $e): bool
    {
        return ($e->errorInfo[0] ?? null) === '23000'
            && ((int) ($e->errorInfo[1] ?? 0)) === 1062;
    }

    /**
     * The storno that reverses this transaction, if it has one.
     *
     * Used for the clean "already stornoed" answer and to show the linkage in
     * the journal. The unique index guarantees there is at most one.
     */
    public function findStornoFor(string $transactionId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT *, occurred_at AS created_at FROM transactions
             WHERE related_transaction_id = ? AND transaction_type = ? LIMIT 1'
        );
        $stmt->execute([$transactionId, 'storno']);
        return $stmt->fetch() ?: null;
    }

    /**
     * Store a storno, letting the database arbitrate whether it is the first.
     *
     * This exists because `insertTransaction()` reads *every* duplicate key as
     * a terminal replaying its batch and answers `null`. For a storno that is
     * exactly wrong: the unique index on `related_transaction_id` is what makes
     * "stornoable at most once" true, and swallowing its violation would report
     * the second storno as a success that wrote nothing. The service's own
     * already-stornoed lookup cannot cover this — two concurrent requests both
     * read "not yet stornoed" before either writes, so the index is the only
     * arbiter (#169).
     *
     * @throws TransactionAlreadyStornoedException when this transaction already has a storno
     * @throws TransactionNotStorableException when the database refuses the row for any other reason
     */
    public function insertStorno(array $data): array
    {
        $result = $this->insertTransaction($data);

        if ($result === null) {
            // `null` means insertTransaction() absorbed a duplicate key. It does
            // not say *which* one, but for a storno there is only one candidate:
            // the id is freshly generated per call, so the collision is the
            // unique index on related_transaction_id — this transaction already
            // has a storno.
            //
            // Note this is the ONLY path. Catching a PDOException here as well
            // would be dead code: isDuplicateKey() matches errno 1062 without
            // regard to which index raised it, so insertTransaction() converts
            // the violation to `null` before it can propagate.
            throw new TransactionAlreadyStornoedException('This transaction has already been stornoed');
        }

        return $result;
    }

    /**
     * Whether the database refused this particular row, as opposed to failing
     * for a reason a retry could clear.
     *
     * The three SQLSTATE classes below are what MariaDB raises for a row it
     * will refuse identically forever:
     *
     * | Class | Raised for | Example |
     * |---|---|---|
     * | `23` | integrity constraint | dangling `member_id` (1452), storno without a link (4025) |
     * | `22` | data exception | `occurred_at` that is not a datetime (1292) |
     * | `01` | truncation, promoted to an error by `STRICT_TRANS_TABLES` | `transaction_type` outside the ENUM (1265) |
     *
     * Everything else — a dropped connection, a deadlock, a lock timeout — is
     * transient and keeps propagating, because the terminal *should* retry it.
     */
    private static function isRowRefused(\PDOException $e): bool
    {
        $sqlstate = (string) ($e->errorInfo[0] ?? $e->getCode());
        return str_starts_with($sqlstate, '23')
            || str_starts_with($sqlstate, '22')
            || str_starts_with($sqlstate, '01');
    }

    /**
     * A member's Deckel: what they still owe the club, positive, or the credit
     * the club owes them, negative. Settled transactions drop out — a member
     * whose tab has been collected is back at zero (ruling #141).
     *
     * This is the *only* per-member balance. The lifetime sum over every
     * transaction ever booked used to sit beside it and was what the terminal
     * and the admin panel actually displayed; it ignored settlement runs and
     * therefore grew forever, so #83 deleted it rather than leave a second,
     * plausible-looking figure within reach.
     */
    public function getUnsettledMemberBalanceCents(string $memberId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(SUM(t.amount_cents), 0)
             FROM transactions t
             WHERE t.member_id = ?
               AND ' . UnsettledTransactions::UNSETTLED
        );
        $stmt->execute([$memberId]);
        return (int) $stmt->fetchColumn();
    }

    public function hasMemberInActiveSettlement(string $memberId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT EXISTS (
                SELECT 1 FROM settlement_items si
                WHERE si.member_id = ? AND si.active_transaction_id IS NOT NULL
            )'
        );
        $stmt->execute([$memberId]);
        return (bool) $stmt->fetchColumn();
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
            $where[] = 't.occurred_at >= ?';
            $params[] = $since;
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        // occurred_at is the terminal-owned sale time; the API still calls it created_at until #172 renames the contract.
        $stmt = $this->db->prepare(
            "SELECT t.*,
                    t.occurred_at AS created_at,
                    p.names as product_names,
                    p.icon_name as product_icon,
                    s.id as settlement_id,
                    s.settlement_date
             FROM transactions t
             LEFT JOIN products p ON t.product_id = p.id
             LEFT JOIN settlement_items si ON si.active_transaction_id = t.id
             LEFT JOIN settlements s ON si.settlement_id = s.id
             {$whereClause}
             ORDER BY t.occurred_at DESC
             LIMIT {$limit} OFFSET {$offset}"
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
            $where[] = 't.occurred_at >= ?';
            $params[] = $filters['date_from'];
        }
        if (isset($filters['date_to'])) {
            $where[] = 't.occurred_at <= ?';
            $params[] = UnsettledTransactions::endOfDay((string) $filters['date_to']);
        }
        if (isset($filters['search'])) {
            $escaped = SafeQuery::escapeLike($filters['search']);
            $lowerEscaped = mb_strtolower($escaped);
            // p.names uses utf8mb4_bin collation, so use LOWER() for case-insensitive search
            $where[] = "(CONCAT(m.first_name, ' ', m.last_name) LIKE ? OR t.notes LIKE ? OR LOWER(p.names) LIKE ?)";
            $params = array_merge($params, ["%{$escaped}%", "%{$escaped}%", "%{$lowerEscaped}%"]);
        }
        if (isset($filters['settlement_status'])) {
            if ($filters['settlement_status'] === 'unsettled') {
                $where[] = UnsettledTransactions::UNSETTLED;
            } elseif ($filters['settlement_status'] === 'settled') {
                $where[] = UnsettledTransactions::SETTLED;
            }
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // API sort key stays created_at; it now maps to the occurred_at column.
        $sortMap = ['created_at' => 't.occurred_at', 'amount' => 't.amount_cents', 'type' => 't.transaction_type', 'member_name' => 'm.last_name', 'member' => 'm.last_name'];
        $sortCol = $sortMap[$sortKey] ?? 't.occurred_at';
        $dir = SafeQuery::direction($sortOrder);

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM transactions t LEFT JOIN members m ON t.member_id = m.id LEFT JOIN products p ON t.product_id = p.id {$whereClause}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $dataParams = array_merge($params, [$limit, $offset]);
        // occurred_at is the terminal-owned sale time; the API still calls it created_at until #172 renames the contract.
        $stmt = $this->db->prepare(
            // stornoed_by_transaction_id makes the linkage visible in both
            // directions (#169). related_transaction_id points from a storno to
            // its original; without the reverse the journal could only show the
            // original as reversed when its storno happened to land on the same
            // page, and the row action could not be reliably disabled.
            "SELECT t.*, t.occurred_at AS created_at, CONCAT(m.first_name, ' ', m.last_name) as member_name, m.first_name, m.last_name, m.email, p.names as product_names, (SELECT s.settlement_date FROM settlement_items si JOIN settlements s ON si.settlement_id = s.id WHERE si.active_transaction_id = t.id LIMIT 1) as settlement_date, (SELECT st.id FROM transactions st WHERE st.related_transaction_id = t.id AND st.transaction_type = 'storno' LIMIT 1) as stornoed_by_transaction_id FROM transactions t LEFT JOIN members m ON t.member_id = m.id LEFT JOIN products p ON t.product_id = p.id {$whereClause} ORDER BY {$sortCol} {$dir} LIMIT ? OFFSET ?"
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

    /**
     * Every unsettled transaction belonging to the given members, whenever it
     * occurred (#161 §2, ruling #141).
     *
     * A settlement run sweeps each participant's whole unsettled position:
     * settling only the slice inside the run's window is what let a January
     * credit sit unsettled and invisible while February was collected in full.
     *
     * @param list<string> $memberIds
     */
    public function findUnsettledByMemberIds(array $memberIds): array
    {
        if (empty($memberIds)) return [];

        [$placeholders, $params] = SafeQuery::inClause(array_values($memberIds), 'string');
        $stmt = $this->db->prepare(
            "SELECT t.* FROM transactions t WHERE t.member_id IN ({$placeholders}) AND " . UnsettledTransactions::UNSETTLED . " ORDER BY t.occurred_at ASC"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function findUnsettledByIds(array $transactionIds): array
    {
        if (empty($transactionIds)) return [];

        [$placeholders, $params] = SafeQuery::inClause($transactionIds, 'string');
        $stmt = $this->db->prepare(
            "SELECT t.* FROM transactions t WHERE t.id IN ({$placeholders}) AND " . UnsettledTransactions::UNSETTLED . ""
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function countRecentTransactions(int $days = 30): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM transactions WHERE occurred_at >= DATE_SUB(NOW(), INTERVAL ? DAY)');
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
             WHERE ' . UnsettledTransactions::UNSETTLED
        );
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    /**
     * Return aggregate stats for transactions matching the given filters,
     * restricted to only unsettled transactions.
     * Accepted filter keys: date_from, date_to, search, member_id
     *
     * @return array{ transaction_count: int, member_count: int, total_amount_cents: int }
     */
    public function summarizeUnsettledByFilters(array $filters = []): array
    {
        [$where, $params] = UnsettledTransactions::buildUnsettledWhere($filters);
        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) as transaction_count,
                    COUNT(DISTINCT t.member_id) as member_count,
                    COALESCE(SUM(t.amount_cents), 0) as total_amount_cents
             FROM transactions t
             LEFT JOIN members m ON t.member_id = m.id
             LEFT JOIN products p ON t.product_id = p.id
             {$whereClause}"
        );
        $stmt->execute($params);
        $row = $stmt->fetch();
        if ($row === false) {
            return ['transaction_count' => 0, 'member_count' => 0, 'total_amount_cents' => 0];
        }

        return [
            'transaction_count'  => (int) $row['transaction_count'],
            'member_count'       => (int) $row['member_count'],
            'total_amount_cents' => (int) $row['total_amount_cents'],
        ];
    }

    /**
     * Fetch IDs of all unsettled transactions matching filters.
     * Accepted filter keys: date_from, date_to, search, member_id
     *
     * @return string[]
     */
    public function findAllUnsettledByFilters(array $filters = []): array
    {
        [$where, $params] = UnsettledTransactions::buildUnsettledWhere($filters);
        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $stmt = $this->db->prepare(
            "SELECT t.id
             FROM transactions t
             LEFT JOIN members m ON t.member_id = m.id
             LEFT JOIN products p ON t.product_id = p.id
             {$whereClause}
             ORDER BY t.occurred_at ASC"
        );
        $stmt->execute($params);
        return array_column($stmt->fetchAll(), 'id');
    }
}
