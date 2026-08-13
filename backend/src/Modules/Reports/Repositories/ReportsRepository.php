<?php

declare(strict_types=1);

namespace App\Modules\Reports\Repositories;

use App\Modules\Reports\Domain\ReportFilters;
use PDO;

/**
 * The SQL behind the reporting endpoints (Pattern 005).
 *
 * ReportsService used to hold a PDO and build these statements inline, which
 * made every report untestable without a database. The grouping dialect —
 * which expression names a "category", how a week is spelled — is SQL, so it
 * lives here; *which* groupings are offered at all is the service's call.
 */
class ReportsRepository
{
    /** group_by value → [dimension SELECT, GROUP BY clause, extra JOINs] */
    private const GROUP_BY_SQL = [
        'category' => [
            "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(c.names, '$.de')), JSON_UNQUOTE(JSON_EXTRACT(c.names, '$.en')), 'Unknown')",
            'p.category_id',
            'LEFT JOIN categories c ON p.category_id = c.id',
        ],
        'product' => [
            "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(p.names, '$.de')), JSON_UNQUOTE(JSON_EXTRACT(p.names, '$.en')), 'Unknown')",
            'p.id',
            '',
        ],
        'member' => [
            "CONCAT(m.first_name, ' ', m.last_name)",
            'm.id',
            'LEFT JOIN members m ON t.member_id = m.id',
        ],
        'day' => ['DATE(t.occurred_at)', 'DATE(t.occurred_at)', ''],
        'week' => [
            "CONCAT(YEAR(t.occurred_at), '-W', LPAD(WEEK(t.occurred_at, 1), 2, '0'))",
            'YEAR(t.occurred_at), WEEK(t.occurred_at, 1)',
            '',
        ],
        'month' => ["DATE_FORMAT(t.occurred_at, '%Y-%m')", "DATE_FORMAT(t.occurred_at, '%Y-%m')", ''],
        'year' => ['YEAR(t.occurred_at)', 'YEAR(t.occurred_at)', ''],
    ];

    /** Sort keys the grouped query accepts, mapped to their ORDER BY clause. */
    private const ORDER_BY_SQL = [
        'revenue' => 'revenue_cents DESC',
        'count' => 'count DESC',
    ];

    public function __construct(private PDO $db) {}

    /**
     * Totals across the whole filtered set, before grouping.
     *
     * @return array{total_revenue_cents: int, transaction_count: int, unique_member_count: int}
     */
    public function summary(ReportFilters $filters): array
    {
        [$where, $params] = $this->purchaseConditions($filters);

        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(t.amount_cents), 0) as total_revenue_cents,
                    COUNT(DISTINCT t.id) as transaction_count,
                    COUNT(DISTINCT t.member_id) as unique_member_count
             FROM transactions t
             LEFT JOIN products p ON t.product_id = p.id
             WHERE {$where}"
        );
        $stmt->execute($params);
        $row = $stmt->fetch() ?: [];

        return [
            'total_revenue_cents' => (int) ($row['total_revenue_cents'] ?? 0),
            'transaction_count' => (int) ($row['transaction_count'] ?? 0),
            'unique_member_count' => (int) ($row['unique_member_count'] ?? 0),
        ];
    }

    /**
     * How many groups the filtered set falls into — the pagination total.
     */
    public function countGroups(ReportFilters $filters, string $groupBy): int
    {
        [$where, $params] = $this->purchaseConditions($filters);
        [, $groupByClause, $joins] = $this->groupBySql($groupBy);

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM (
                SELECT 1
                FROM transactions t
                LEFT JOIN products p ON t.product_id = p.id
                {$joins}
                WHERE {$where}
                GROUP BY {$groupByClause}
            ) as grouped"
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * One page of grouped rows.
     *
     * @param 'revenue'|'count' $orderBy
     * @return list<array{dimension: string, revenue_cents: int, count: int}>
     */
    public function fetchGrouped(
        ReportFilters $filters,
        string $groupBy,
        string $orderBy,
        int $limit,
        int $offset,
    ): array {
        [$where, $params] = $this->purchaseConditions($filters);
        [$dimensionSelect, $groupByClause, $joins] = $this->groupBySql($groupBy);
        $orderByClause = self::ORDER_BY_SQL[$orderBy] ?? self::ORDER_BY_SQL['revenue'];
        $bounds = $this->limitOffset($limit, $offset);

        $stmt = $this->db->prepare(
            "SELECT {$dimensionSelect} as dimension,
                    SUM(t.amount_cents) as revenue_cents,
                    COUNT(DISTINCT t.id) as count
             FROM transactions t
             LEFT JOIN products p ON t.product_id = p.id
             {$joins}
             WHERE {$where}
             GROUP BY {$groupByClause}
             ORDER BY {$orderByClause}
             {$bounds}"
        );
        $stmt->execute($params);

        return array_map(static fn(array $row): array => [
            'dimension' => (string) $row['dimension'],
            'revenue_cents' => (int) $row['revenue_cents'],
            'count' => (int) $row['count'],
        ], $stmt->fetchAll());
    }

    /**
     * Every transaction in the window, oldest first, for session grouping.
     *
     * occurred_at (not received_at) drives the ordering: back-to-back sales at
     * the till, not sync arrival order, define a "session".
     *
     * @return list<array{amount_cents: int, occurred_at: string}>
     */
    public function transactionsForActivity(ReportFilters $filters): array
    {
        [$where, $params] = $this->activityConditions($filters);

        $stmt = $this->db->prepare(
            "SELECT t.amount_cents, t.occurred_at
             FROM transactions t
             WHERE {$where}
             ORDER BY t.occurred_at ASC"
        );
        $stmt->execute($params);

        return array_map(static fn(array $row): array => [
            'amount_cents' => (int) $row['amount_cents'],
            'occurred_at' => (string) $row['occurred_at'],
        ], $stmt->fetchAll());
    }

    /**
     * Transactions per hour of day, for the hours that saw any.
     *
     * @return array<int, int> hour (0-23) → transaction count
     */
    public function hourlyDistribution(ReportFilters $filters): array
    {
        [$where, $params] = $this->activityConditions($filters);

        $stmt = $this->db->prepare(
            "SELECT HOUR(t.occurred_at) as hour, COUNT(*) as transaction_count
             FROM transactions t
             WHERE {$where}
             GROUP BY HOUR(t.occurred_at)
             ORDER BY hour"
        );
        $stmt->execute($params);

        $byHour = [];
        foreach ($stmt->fetchAll() as $row) {
            $byHour[(int) $row['hour']] = (int) $row['transaction_count'];
        }

        return $byHour;
    }

    /**
     * Per-terminal totals for the window, busiest first.
     *
     * last_sync_at is deliberately not selected here: terminals sync on a fixed
     * background timer regardless of whether anyone is using them, so it
     * reflects heartbeat rather than business activity. It stays available on
     * the terminal settings page instead.
     *
     * @return list<array{id: string, name: string, transaction_count: int}>
     */
    public function terminalSummary(ReportFilters $filters): array
    {
        [$where, $params] = $this->activityConditions($filters);

        $stmt = $this->db->prepare(
            "SELECT te.id, te.name, COUNT(t.id) as transaction_count
             FROM transactions t
             JOIN terminals te ON t.created_by_terminal_id = te.id
             WHERE {$where}
             GROUP BY te.id
             ORDER BY transaction_count DESC"
        );
        $stmt->execute($params);

        return array_map(static fn(array $row): array => [
            'id' => (string) $row['id'],
            'name' => (string) $row['name'],
            'transaction_count' => (int) $row['transaction_count'],
        ], $stmt->fetchAll());
    }

    /**
     * @return array{string, string, string}
     */
    private function groupBySql(string $groupBy): array
    {
        return self::GROUP_BY_SQL[$groupBy]
            ?? throw new \InvalidArgumentException("Invalid group_by: {$groupBy}");
    }

    /**
     * Conditions shared by the revenue/consumption reports: purchases only.
     *
     * @return array{string, list<string>}
     */
    private function purchaseConditions(ReportFilters $filters): array
    {
        [$where, $params] = $this->purchaseDateConditions($filters);
        $conditions = [$where];

        if ($filters->categoryIds !== []) {
            $conditions[] = 'p.category_id IN (' . $this->placeholders($filters->categoryIds) . ')';
            $params = array_merge($params, $filters->categoryIds);
        }
        if ($filters->productIds !== []) {
            $conditions[] = 't.product_id IN (' . $this->placeholders($filters->productIds) . ')';
            $params = array_merge($params, $filters->productIds);
        }

        return [implode(' AND ', $conditions), $params];
    }

    /**
     * Purchases inside the date range — the part every purchase report shares,
     * and all a query that cannot reach the products table can use.
     *
     * @return array{string, list<string>}
     */
    private function purchaseDateConditions(ReportFilters $filters): array
    {
        $conditions = ["t.transaction_type = 'purchase'"];
        $params = [];

        $this->appendDateRange($filters, $conditions, $params);

        return [implode(' AND ', $conditions), $params];
    }

    /**
     * Conditions for the terminal-activity report: every transaction type, one
     * terminal optionally.
     *
     * @return array{string, list<string>}
     */
    private function activityConditions(ReportFilters $filters): array
    {
        $conditions = ['1=1'];
        $params = [];

        $this->appendDateRange($filters, $conditions, $params);

        if ($filters->terminalId !== null) {
            $conditions[] = 't.created_by_terminal_id = ?';
            $params[] = $filters->terminalId;
        }

        return [implode(' AND ', $conditions), $params];
    }

    /**
     * @param list<string> $conditions
     * @param list<string> $params
     */
    private function appendDateRange(ReportFilters $filters, array &$conditions, array &$params): void
    {
        if ($filters->dateFrom) {
            $conditions[] = 't.occurred_at >= ?';
            $params[] = $filters->dateFrom;
        }
        if ($filters->dateTo) {
            $conditions[] = 't.occurred_at < DATE_ADD(?, INTERVAL 1 DAY)';
            $params[] = $filters->dateTo;
        }
    }

    /**
     * @param list<string> $values
     */
    private function placeholders(array $values): string
    {
        return implode(',', array_fill(0, count($values), '?'));
    }

    /**
     * LIMIT/OFFSET are interpolated rather than bound: the filter values already
     * occupy positional `?` placeholders and PDO refuses to mix those with named
     * ones in a single statement. Casting to int here is what makes that safe —
     * nothing but a number can reach the SQL, whatever the caller passed.
     */
    private function limitOffset(int $limit, ?int $offset = null): string
    {
        $limit = max(0, $limit);
        $clause = "LIMIT {$limit}";

        return $offset === null ? $clause : $clause . ' OFFSET ' . max(0, $offset);
    }
}
