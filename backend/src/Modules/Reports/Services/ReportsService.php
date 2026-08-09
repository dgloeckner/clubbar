<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services;

use App\Modules\Reports\DTOs\ReportDto;
use App\Modules\Reports\DTOs\ReportRowDto;
use App\Shared\Utils\Csv;
use PDO;

class ReportsService
{
    private const VALID_REPORT_TYPES = ['revenue', 'consumption', 'transactions'];
    private const VALID_GROUP_BY = ['category', 'product', 'member', 'day', 'week', 'month', 'year'];
    private const DEFAULT_PER_PAGE = 25;
    private const MAX_PER_PAGE = 100;

    public function __construct(private PDO $db) {}

    public function getReport(
        string $reportType,
        ?string $dateFrom,
        ?string $dateTo,
        string $groupBy = 'month',
        ?string $categoryIds = null,
        ?string $productIds = null,
        int $page = 1,
        int $perPage = self::DEFAULT_PER_PAGE,
    ): ReportDto {
        if (!in_array($reportType, self::VALID_REPORT_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid report type: {$reportType}");
        }
        if (!in_array($groupBy, self::VALID_GROUP_BY, true)) {
            throw new \InvalidArgumentException("Invalid group_by: {$groupBy}");
        }

        $perPage = min(max($perPage, 1), self::MAX_PER_PAGE);
        $page = max($page, 1);

        // Build WHERE clause
        $conditions = ["t.transaction_type = 'purchase'"];
        $params = [];

        if ($dateFrom) {
            $conditions[] = 't.occurred_at >= ?';
            $params[] = $dateFrom;
        }
        if ($dateTo) {
            $conditions[] = 't.occurred_at < DATE_ADD(?, INTERVAL 1 DAY)';
            $params[] = $dateTo;
        }
        if ($categoryIds) {
            $ids = array_filter(explode(',', $categoryIds));
            if ($ids) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $conditions[] = "p.category_id IN ({$placeholders})";
                $params = array_merge($params, $ids);
            }
        }
        if ($productIds) {
            $ids = array_filter(explode(',', $productIds));
            if ($ids) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $conditions[] = "t.product_id IN ({$placeholders})";
                $params = array_merge($params, $ids);
            }
        }

        $where = implode(' AND ', $conditions);

        // Get summary (no grouping, no pagination)
        $summaryParams = $params; // same filters
        $summaryStmt = $this->db->prepare(
            "SELECT COALESCE(SUM(t.amount_cents), 0) as total_revenue_cents,
                    COUNT(DISTINCT t.id) as transaction_count,
                    COUNT(DISTINCT t.member_id) as unique_member_count
             FROM transactions t
             LEFT JOIN products p ON t.product_id = p.id
             WHERE {$where}"
        );
        $summaryStmt->execute($summaryParams);
        $summary = $summaryStmt->fetch();

        $totalRevenueCents = (int) $summary['total_revenue_cents'];
        $transactionCount = (int) $summary['transaction_count'];
        $uniqueMemberCount = (int) $summary['unique_member_count'];
        $avgTransactionCents = $transactionCount > 0 ? (int) round($totalRevenueCents / $transactionCount) : 0;

        // Build GROUP BY and dimension SELECT
        [$dimensionSelect, $groupByClause, $joins] = $this->buildGroupBy($groupBy);

        // Count total groups for pagination
        $countStmt = $this->db->prepare(
            "SELECT COUNT(*) FROM (
                SELECT 1
                FROM transactions t
                LEFT JOIN products p ON t.product_id = p.id
                {$joins}
                WHERE {$where}
                GROUP BY {$groupByClause}
            ) as grouped"
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // Consumption report is sorted by count (popularity), others by revenue
        $orderBy = $reportType === 'consumption' ? 'count DESC' : 'revenue_cents DESC';

        // Get grouped data with pagination
        $offset = ($page - 1) * $perPage;
        $dataStmt = $this->db->prepare(
            "SELECT {$dimensionSelect} as dimension,
                    SUM(t.amount_cents) as revenue_cents,
                    COUNT(DISTINCT t.id) as count
             FROM transactions t
             LEFT JOIN products p ON t.product_id = p.id
             {$joins}
             WHERE {$where}
             GROUP BY {$groupByClause}
             ORDER BY {$orderBy}
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $dataStmt->execute($params);
        $rows = $dataStmt->fetchAll();

        $data = [];
        foreach ($rows as $row) {
            $revCents = (int) $row['revenue_cents'];
            $pct = $totalRevenueCents > 0 ? ($revCents / $totalRevenueCents) * 100 : 0;
            $data[] = new ReportRowDto(
                dimension: (string) $row['dimension'],
                revenueCents: $revCents,
                count: (int) $row['count'],
                percentOfTotal: $pct,
            );
        }

        return new ReportDto(
            reportType: $reportType,
            filters: array_filter([
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'group_by' => $groupBy,
                'category_ids' => $categoryIds,
                'product_ids' => $productIds,
            ]),
            totalRevenueCents: $totalRevenueCents,
            uniqueMemberCount: $uniqueMemberCount,
            transactionCount: $transactionCount,
            avgTransactionCents: $avgTransactionCents,
            data: $data,
            page: $page,
            perPage: $perPage,
            total: $total,
        );
    }

    /**
     * Export report data as CSV string (no pagination).
     */
    public function exportCsv(
        string $reportType,
        ?string $dateFrom,
        ?string $dateTo,
        string $groupBy = 'month',
    ): string {
        // Use a large page to get all data
        $report = $this->getReport($reportType, $dateFrom, $dateTo, $groupBy, null, null, 1, 10000);

        // Euros, semicolons and RFC 4180 quoting, like every other export
        // (#119) — this one used to write commas and raw cents.
        return Csv::build(
            ['Dimension', 'Revenue EUR', 'Count', '% of Total'],
            array_map(static function ($row): array {
                $arr = $row->toArray();

                return [
                    $arr['dimension'],
                    Csv::money((int) $arr['revenue_cents']),
                    $arr['count'],
                    $arr['percent_of_total'],
                ];
            }, $report->data),
        );
    }

    /**
     * Export the member ranking as a CSV string (UC-A51).
     *
     * Anonymous like the on-screen ranking — a file is the easiest way for a
     * profile to leave the building, so it gets no named mode either (#177).
     */
    public function exportMemberRankingCsv(
        ?string $dateFrom,
        ?string $dateTo,
        int $limit = 25,
    ): string {
        $ranking = $this->getMemberRanking($dateFrom, $dateTo, $limit);

        return Csv::build(
            ['Rank', 'Member', 'Total EUR', 'Transactions'],
            array_map(static fn(array $row): array => [
                $row['rank'],
                $row['member_name'],
                Csv::money((int) $row['total_amount_cents']),
                $row['transaction_count'],
            ], $ranking['data']),
        );
    }

    /**
     * Export the terminal activity report as a CSV string (UC-A52).
     *
     * The report is three tables on screen, so it is three blocks in the file —
     * exporting only the sessions would hand back less than the button promises.
     */
    public function exportTerminalActivityCsv(
        string $dateFrom,
        string $dateTo,
        ?string $terminalId = null,
    ): string {
        $activity = $this->getTerminalActivity($dateFrom, $dateTo, $terminalId);

        $sessions = Csv::build(
            ['Date', 'Start', 'End', 'Transactions', 'Revenue EUR'],
            array_map(static fn(array $s): array => [
                $s['date'],
                $s['start_time'],
                $s['end_time'],
                $s['transaction_count'],
                Csv::money((int) $s['revenue_cents']),
            ], $activity['sessions']),
        );

        $hourly = Csv::build(
            ['Hour', 'Transactions'],
            array_map(static fn(array $b): array => [
                sprintf('%02d:00', (int) $b['hour']),
                $b['transaction_count'],
            ], $activity['hourly_distribution']),
        );

        $terminals = Csv::build(
            ['Terminal', 'Transactions', 'Last Sync'],
            array_map(static fn(array $t): array => [
                $t['name'],
                $t['transaction_count'],
                $t['last_sync_at'],
            ], $activity['terminals']),
        );

        return "Sessions\n" . $sessions
            . "\nHourly Distribution\n" . $hourly
            . "\nTerminals\n" . $terminals;
    }

    /**
     * Get member consumption ranking (UC-A51).
     *
     * The ranking is always anonymous (#177). A named leaderboard of who drinks
     * most is a consumption profile, which ADR-0029 prohibits — and the label is
     * the ordinal position within *this* report, never a stable alias, so a row
     * cannot be re-identified by cross-referencing a second report.
     */
    public function getMemberRanking(
        ?string $dateFrom,
        ?string $dateTo,
        int $limit = 25,
    ): array {
        $limit = min(max($limit, 1), 100);

        $conditions = ["t.transaction_type = 'purchase'"];
        $params = [];

        if ($dateFrom) {
            $conditions[] = 't.occurred_at >= ?';
            $params[] = $dateFrom;
        }
        if ($dateTo) {
            $conditions[] = 't.occurred_at < DATE_ADD(?, INTERVAL 1 DAY)';
            $params[] = $dateTo;
        }

        $where = implode(' AND ', $conditions);

        // Names are not selected at all — the aggregate never carries identity.
        $stmt = $this->db->prepare(
            "SELECT SUM(t.amount_cents) as total_amount_cents,
                    COUNT(*) as transaction_count
             FROM transactions t
             JOIN members m ON t.member_id = m.id
             WHERE {$where}
             GROUP BY m.id
             ORDER BY total_amount_cents DESC
             LIMIT {$limit}"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $data = [];
        $rank = 1;
        foreach ($rows as $row) {
            $data[] = [
                'rank' => $rank,
                'member_name' => "Member {$rank}",
                'total_amount_cents' => (int) $row['total_amount_cents'],
                'transaction_count' => (int) $row['transaction_count'],
            ];
            $rank++;
        }

        return ['data' => $data];
    }

    /**
     * Get terminal activity report (UC-A52).
     * Sessions: gap of 30+ minutes between transactions = new session.
     */
    public function getTerminalActivity(
        string $dateFrom,
        string $dateTo,
        ?string $terminalId = null,
    ): array {
        $conditions = ['1=1'];
        $params = [];

        $conditions[] = 't.occurred_at >= ?';
        $params[] = $dateFrom;
        $conditions[] = 't.occurred_at < DATE_ADD(?, INTERVAL 1 DAY)';
        $params[] = $dateTo;

        if ($terminalId) {
            $conditions[] = 't.created_by_terminal_id = ?';
            $params[] = $terminalId;
        }

        $where = implode(' AND ', $conditions);

        // Get all transactions ordered by time
        // occurred_at (not received_at) drives session grouping: back-to-back sales
        // at the till, not sync arrival order, define a "session" here.
        $stmt = $this->db->prepare(
            "SELECT t.id, t.created_by_terminal_id, t.amount_cents, t.occurred_at,
                    te.name as terminal_name
             FROM transactions t
             LEFT JOIN terminals te ON t.created_by_terminal_id = te.id
             WHERE {$where}
             ORDER BY t.occurred_at ASC"
        );
        $stmt->execute($params);
        $transactions = $stmt->fetchAll();

        // Build sessions (30-minute gap = new session)
        $sessions = [];
        $currentSession = null;
        $sessionGapSeconds = 30 * 60;

        foreach ($transactions as $tx) {
            $txTime = strtotime($tx['occurred_at']);
            if ($currentSession === null || ($txTime - $currentSession['last_time']) > $sessionGapSeconds) {
                if ($currentSession !== null) {
                    $sessions[] = $this->finalizeSession($currentSession);
                }
                $currentSession = [
                    'date' => date('Y-m-d', $txTime),
                    'start_time' => date('H:i:s', $txTime),
                    'last_time' => $txTime,
                    'end_time' => date('H:i:s', $txTime),
                    'transaction_count' => 0,
                    'revenue_cents' => 0,
                ];
            }
            $currentSession['last_time'] = $txTime;
            $currentSession['end_time'] = date('H:i:s', $txTime);
            $currentSession['transaction_count']++;
            $currentSession['revenue_cents'] += (int) $tx['amount_cents'];
        }
        if ($currentSession !== null) {
            $sessions[] = $this->finalizeSession($currentSession);
        }

        // Hourly distribution (all 24 hours)
        $hourlyStmt = $this->db->prepare(
            "SELECT HOUR(t.occurred_at) as hour, COUNT(*) as transaction_count
             FROM transactions t
             WHERE {$where}
             GROUP BY HOUR(t.occurred_at)
             ORDER BY hour"
        );
        $hourlyStmt->execute($params);
        $hourlyRows = $hourlyStmt->fetchAll();
        $hourMap = [];
        foreach ($hourlyRows as $row) {
            $hourMap[(int) $row['hour']] = (int) $row['transaction_count'];
        }
        $hourlyDist = [];
        for ($h = 0; $h < 24; $h++) {
            $hourlyDist[] = ['hour' => $h, 'transaction_count' => $hourMap[$h] ?? 0];
        }

        // Terminal summary
        $terminalStmt = $this->db->prepare(
            "SELECT te.id, te.name, COUNT(t.id) as transaction_count, MAX(te.last_sync_at) as last_sync_at
             FROM transactions t
             JOIN terminals te ON t.created_by_terminal_id = te.id
             WHERE {$where}
             GROUP BY te.id
             ORDER BY transaction_count DESC"
        );
        $terminalStmt->execute($params);
        $terminalRows = $terminalStmt->fetchAll();
        $terminals = [];
        foreach ($terminalRows as $row) {
            $terminals[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'transaction_count' => (int) $row['transaction_count'],
                'last_sync_at' => $row['last_sync_at'],
            ];
        }

        return [
            'sessions' => $sessions,
            'hourly_distribution' => $hourlyDist,
            'terminals' => $terminals,
        ];
    }

    private function finalizeSession(array $session): array
    {
        unset($session['last_time']);
        return $session;
    }

    /**
     * @return array{string, string, string} [dimensionSelect, groupByClause, joins]
     */
    private function buildGroupBy(string $groupBy): array
    {
        return match ($groupBy) {
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
            'day' => [
                'DATE(t.occurred_at)',
                'DATE(t.occurred_at)',
                '',
            ],
            'week' => [
                "CONCAT(YEAR(t.occurred_at), '-W', LPAD(WEEK(t.occurred_at, 1), 2, '0'))",
                'YEAR(t.occurred_at), WEEK(t.occurred_at, 1)',
                '',
            ],
            'month' => [
                "DATE_FORMAT(t.occurred_at, '%Y-%m')",
                "DATE_FORMAT(t.occurred_at, '%Y-%m')",
                '',
            ],
            'year' => [
                'YEAR(t.occurred_at)',
                'YEAR(t.occurred_at)',
                '',
            ],
        };
    }
}
