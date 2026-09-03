<?php

declare(strict_types=1);

namespace App\Modules\Reports\Repositories;

use App\Modules\Reports\Domain\ReportFilters;
use App\Shared\Time\ClubLocalSql;
use App\Shared\Time\ClubTimeZone;
use App\Shared\Utils\DateFormatter;
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
    /**
     * group_by value → the SQL that names a group, identifies it, groups on it
     * and reaches the tables it needs.
     *
     * Two rules hold across every entry:
     *
     * - **`group` keys on a column of `transactions` wherever the transaction
     *   carries one**, never on the join's output. ADR-0033 §1 deliberately
     *   accepts a sale whose `product_id` resolves to nothing — the drink was
     *   poured against a cached catalogue, and refusing the row would lose the
     *   record of a sale that happened. Grouping on `p.id` collapsed every such
     *   sale, whatever product it named, into one NULL group: a fabricated row
     *   that summed unrelated products and sorted straight to the top of the
     *   chart. `t.product_id` keeps them apart.
     * - **`dimension` is NULL when no name can be resolved**, rather than a
     *   literal. It used to be the English word 'Unknown', which a German admin
     *   read untranslated between their own product names. Naming the row is
     *   the frontend's business — the API is language-agnostic — so what the
     *   API owes here is the fact that there is no name, plus the `id` that
     *   had none, which is what tells two dead products apart on screen.
     *
     * @var array<string, array{dimension: string, id: string, group: string, joins: string}>
     */
    private const GROUP_BY_SQL = [
        'category' => [
            'dimension' => "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(c.names, '$.de')), JSON_UNQUOTE(JSON_EXTRACT(c.names, '$.en')))",
            'id' => 'p.category_id',
            'group' => 'p.category_id',
            'joins' => 'LEFT JOIN categories c ON p.category_id = c.id',
        ],
        'product' => [
            'dimension' => "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(p.names, '$.de')), JSON_UNQUOTE(JSON_EXTRACT(p.names, '$.en')))",
            'id' => 't.product_id',
            'group' => 't.product_id',
            'joins' => '',
        ],
        'member' => [
            'dimension' => "CONCAT(m.first_name, ' ', m.last_name)",
            'id' => 't.member_id',
            'group' => 't.member_id',
            'joins' => 'LEFT JOIN members m ON t.member_id = m.id',
        ],
        'day' => ['dimension' => 'DATE({local})', 'id' => 'NULL', 'group' => 'DATE({local})', 'joins' => ''],
        'week' => [
            'dimension' => "CONCAT(YEAR({local}), '-W', LPAD(WEEK({local}, 1), 2, '0'))",
            'id' => 'NULL',
            'group' => 'YEAR({local}), WEEK({local}, 1)',
            'joins' => '',
        ],
        'month' => [
            'dimension' => "DATE_FORMAT({local}, '%Y-%m')",
            'id' => 'NULL',
            'group' => "DATE_FORMAT({local}, '%Y-%m')",
            'joins' => '',
        ],
        'year' => ['dimension' => 'YEAR({local})', 'id' => 'NULL', 'group' => 'YEAR({local})', 'joins' => ''],
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
        $grouping = $this->groupBySql($groupBy, $filters);

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM (
                SELECT 1
                FROM transactions t
                LEFT JOIN products p ON t.product_id = p.id
                {$grouping['joins']}
                WHERE {$where}
                GROUP BY {$grouping['group']}
            ) as grouped"
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * One page of grouped rows.
     *
     * @param 'revenue'|'count' $orderBy
     * @return list<array{dimension: ?string, dimension_id: ?string, revenue_cents: int, count: int}>
     */
    public function fetchGrouped(
        ReportFilters $filters,
        string $groupBy,
        string $orderBy,
        int $limit,
        int $offset,
    ): array {
        [$where, $params] = $this->purchaseConditions($filters);
        $grouping = $this->groupBySql($groupBy, $filters);
        $orderByClause = self::ORDER_BY_SQL[$orderBy] ?? self::ORDER_BY_SQL['revenue'];
        $bounds = $this->limitOffset($limit, $offset);

        $stmt = $this->db->prepare(
            "SELECT {$grouping['dimension']} as dimension,
                    {$grouping['id']} as dimension_id,
                    SUM(t.amount_cents) as revenue_cents,
                    COUNT(DISTINCT t.id) as count
             FROM transactions t
             LEFT JOIN products p ON t.product_id = p.id
             {$grouping['joins']}
             WHERE {$where}
             GROUP BY {$grouping['group']}
             ORDER BY {$orderByClause}
             {$bounds}"
        );
        $stmt->execute($params);

        return array_map(static fn(array $row): array => [
            // Deliberately not cast to string: a null dimension is the report
            // saying "this group has no name", and '' would read as one.
            'dimension' => $row['dimension'] === null ? null : (string) $row['dimension'],
            'dimension_id' => $row['dimension_id'] === null ? null : (string) $row['dimension_id'],
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
            // Labelled like every other instant the API emits, so a consumer
            // that parses it cannot read it as its own local time (#365).
            'occurred_at' => DateFormatter::toUtcIso((string) $row['occurred_at']) ?? (string) $row['occurred_at'],
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

        // The hour the club was in, not the hour UTC was in. This is the one
        // figure with no second surface to disagree with it: an hour-of-day
        // histogram is smooth and believable whichever way it is shifted, so a
        // reader has nothing to check it against (#365).
        $local = $this->localInstant($filters);

        $stmt = $this->db->prepare(
            "SELECT HOUR({$local}) as hour, COUNT(*) as transaction_count
             FROM transactions t
             WHERE {$where}
             GROUP BY HOUR({$local})
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
     * The grouping dialect for `$groupBy`, with `{local}` resolved.
     *
     * The calendar dimensions — day, week, month, year — name a period the
     * *club* had, so they group on `occurred_at` read in the club's zone rather
     * than in the UTC it is stored in (#365). Without it a Saturday evening's
     * takings appear partly under Sunday, and the bar is still plausible enough
     * that nobody notices. {@see ClubLocalSql} explains why this is an offset
     * expression and not `CONVERT_TZ`.
     *
     * @return array{dimension: string, id: string, group: string, joins: string}
     */
    private function groupBySql(string $groupBy, ReportFilters $filters): array
    {
        $grouping = self::GROUP_BY_SQL[$groupBy]
            ?? throw new \InvalidArgumentException("Invalid group_by: {$groupBy}");

        $local = $this->localInstant($filters);

        return [
            'dimension' => str_replace('{local}', $local, $grouping['dimension']),
            'id' => str_replace('{local}', $local, $grouping['id']),
            'group' => str_replace('{local}', $local, $grouping['group']),
            'joins' => $grouping['joins'],
        ];
    }

    /**
     * `t.occurred_at` evaluated in the club's zone, across this query's range.
     *
     * The bounds only decide which daylight-saving transitions the expression
     * has to carry, so an unbounded filter widens rather than breaks it.
     */
    private function localInstant(ReportFilters $filters): string
    {
        return ClubLocalSql::localInstant(
            't.occurred_at',
            $filters->dateFrom !== null ? ClubTimeZone::startsAtUtc($filters->dateFrom) : null,
            $filters->dateTo !== null ? ClubTimeZone::endsBeforeUtc($filters->dateTo) : null,
        );
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
        // A filter names club calendar days, and the column holds UTC: the
        // window is the half-open range of UTC instants those days cover.
        // Comparing the raw day against the column instead put both ends of
        // every range two hours late in Berlin summer.
        if ($filters->dateFrom) {
            $conditions[] = 't.occurred_at >= ?';
            $params[] = ClubTimeZone::startsAtUtc($filters->dateFrom) ?? $filters->dateFrom;
        }
        if ($filters->dateTo) {
            $conditions[] = 't.occurred_at < ?';
            $params[] = ClubTimeZone::endsBeforeUtc($filters->dateTo)
                ?? (new \DateTimeImmutable($filters->dateTo . ' +1 day'))->format('Y-m-d H:i:s');
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
