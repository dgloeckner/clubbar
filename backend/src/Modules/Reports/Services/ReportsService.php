<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services;

use App\Modules\Reports\DTOs\ReportDto;
use App\Modules\Reports\DTOs\ReportRowDto;
use App\Modules\Reports\Domain\ReportFilters;
use App\Modules\Reports\Repositories\ReportsRepository;

class ReportsService
{
    private const VALID_REPORT_TYPES = ['revenue', 'consumption', 'transactions'];
    private const VALID_GROUP_BY = ['category', 'product', 'member', 'day', 'week', 'month', 'year'];
    private const DEFAULT_PER_PAGE = 25;
    private const MAX_PER_PAGE = 100;

    /** A gap of this long between transactions starts a new terminal session. */
    private const SESSION_GAP_SECONDS = 30 * 60;

    public function __construct(private ReportsRepository $repository) {}

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

        $filters = ReportFilters::fromRequest($dateFrom, $dateTo, $categoryIds, $productIds);

        $summary = $this->repository->summary($filters);
        $totalRevenueCents = $summary['total_revenue_cents'];
        $transactionCount = $summary['transaction_count'];
        $avgTransactionCents = $transactionCount > 0 ? (int) round($totalRevenueCents / $transactionCount) : 0;

        $total = $this->repository->countGroups($filters, $groupBy);

        // Consumption report is sorted by count (popularity), others by revenue
        $orderBy = $reportType === 'consumption' ? 'count' : 'revenue';

        $rows = $this->repository->fetchGrouped(
            $filters,
            $groupBy,
            $orderBy,
            $perPage,
            ($page - 1) * $perPage,
        );

        $data = array_map(static fn(array $row): ReportRowDto => new ReportRowDto(
            dimension: $row['dimension'],
            dimensionId: $row['dimension_id'],
            revenueCents: $row['revenue_cents'],
            count: $row['count'],
            percentOfTotal: $totalRevenueCents > 0 ? ($row['revenue_cents'] / $totalRevenueCents) * 100 : 0,
        ), $rows);

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
            uniqueMemberCount: $summary['unique_member_count'],
            transactionCount: $transactionCount,
            avgTransactionCents: $avgTransactionCents,
            data: $data,
            page: $page,
            perPage: $perPage,
            total: $total,
        );
    }

    /**
     * Get terminal activity report (UC-A52).
     *
     * @return array{sessions: list<array<string, mixed>>, hourly_distribution: list<array{hour: int, transaction_count: int}>, terminals: list<array<string, mixed>>}
     */
    public function getTerminalActivity(
        string $dateFrom,
        string $dateTo,
        ?string $terminalId = null,
    ): array {
        $filters = ReportFilters::fromRequest($dateFrom, $dateTo, terminalId: $terminalId);

        $byHour = $this->repository->hourlyDistribution($filters);
        $hourlyDist = [];
        for ($hour = 0; $hour < 24; $hour++) {
            $hourlyDist[] = ['hour' => $hour, 'transaction_count' => $byHour[$hour] ?? 0];
        }

        return [
            'sessions' => self::groupIntoSessions($this->repository->transactionsForActivity($filters)),
            'hourly_distribution' => $hourlyDist,
            'terminals' => $this->repository->terminalSummary($filters),
        ];
    }

    /**
     * Split a time-ordered run of transactions into till sessions: a gap of
     * SESSION_GAP_SECONDS or more between two sales ends one and starts the next.
     *
     * @param list<array{amount_cents: int, occurred_at: string}> $transactions
     * @return list<array{date: string, start_time: string, end_time: string, transaction_count: int, revenue_cents: int}>
     */
    public static function groupIntoSessions(array $transactions): array
    {
        $sessions = [];
        $current = null;
        $lastTime = 0;

        foreach ($transactions as $transaction) {
            $time = strtotime($transaction['occurred_at']);

            if ($current === null || ($time - $lastTime) > self::SESSION_GAP_SECONDS) {
                if ($current !== null) {
                    $sessions[] = $current;
                }
                $current = [
                    'date' => date('Y-m-d', $time),
                    'start_time' => date('H:i:s', $time),
                    'end_time' => date('H:i:s', $time),
                    'transaction_count' => 0,
                    'revenue_cents' => 0,
                ];
            }

            $lastTime = $time;
            $current['end_time'] = date('H:i:s', $time);
            $current['transaction_count']++;
            $current['revenue_cents'] += $transaction['amount_cents'];
        }

        if ($current !== null) {
            $sessions[] = $current;
        }

        return $sessions;
    }
}
