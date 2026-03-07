<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services;

use App\Modules\Reports\DTOs\ReportDto;
use App\Modules\Reports\DTOs\ReportRowDto;
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
            $conditions[] = 't.created_at >= ?';
            $params[] = $dateFrom;
        }
        if ($dateTo) {
            $conditions[] = 't.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
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
                    COUNT(*) as total_quantity,
                    COUNT(DISTINCT t.id) as transaction_count
             FROM transactions t
             LEFT JOIN products p ON t.product_id = p.id
             WHERE {$where}"
        );
        $summaryStmt->execute($summaryParams);
        $summary = $summaryStmt->fetch();

        $totalRevenueCents = (int) $summary['total_revenue_cents'];
        $totalQuantity = (int) $summary['total_quantity'];
        $transactionCount = (int) $summary['transaction_count'];
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

        // Get grouped data with pagination
        $offset = ($page - 1) * $perPage;
        $dataStmt = $this->db->prepare(
            "SELECT {$dimensionSelect} as dimension,
                    SUM(t.amount_cents) as revenue_cents,
                    COUNT(*) as quantity,
                    COUNT(DISTINCT t.id) as count
             FROM transactions t
             LEFT JOIN products p ON t.product_id = p.id
             {$joins}
             WHERE {$where}
             GROUP BY {$groupByClause}
             ORDER BY revenue_cents DESC
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
                quantity: (int) $row['quantity'],
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
            totalQuantity: $totalQuantity,
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

        $lines = [];
        $lines[] = implode(',', ['Dimension', 'Revenue (cents)', 'Quantity', 'Count', '% of Total']);
        foreach ($report->data as $row) {
            $arr = $row->toArray();
            $lines[] = implode(',', [
                '"' . str_replace('"', '""', $arr['dimension']) . '"',
                $arr['revenue_cents'],
                $arr['quantity'],
                $arr['count'],
                $arr['percent_of_total'],
            ]);
        }

        return implode("\n", $lines) . "\n";
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
                'DATE(t.created_at)',
                'DATE(t.created_at)',
                '',
            ],
            'week' => [
                "CONCAT(YEAR(t.created_at), '-W', LPAD(WEEK(t.created_at, 1), 2, '0'))",
                'YEAR(t.created_at), WEEK(t.created_at, 1)',
                '',
            ],
            'month' => [
                "DATE_FORMAT(t.created_at, '%Y-%m')",
                "DATE_FORMAT(t.created_at, '%Y-%m')",
                '',
            ],
            'year' => [
                'YEAR(t.created_at)',
                'YEAR(t.created_at)',
                '',
            ],
        };
    }
}
