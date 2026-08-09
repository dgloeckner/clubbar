<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services;

use App\Modules\Reports\DTOs\ReportDto;
use App\Modules\Reports\DTOs\ReportRowDto;
use App\Modules\Reports\Domain\ReportFilters;
use App\Modules\Reports\Repositories\ReportsRepository;
use App\Shared\Utils\Csv;

class ReportsService
{
    private const VALID_REPORT_TYPES = ['revenue', 'consumption', 'transactions'];
    private const VALID_GROUP_BY = ['category', 'product', 'member', 'day', 'week', 'month', 'year'];
    private const DEFAULT_PER_PAGE = 25;
    private const MAX_PER_PAGE = 100;
    private const MAX_RANKING_LIMIT = 100;

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
     *
     * @return array{data: list<array<string, mixed>>}
     */
    public function getMemberRanking(
        ?string $dateFrom,
        ?string $dateTo,
        int $limit = 25,
    ): array {
        $limit = min(max($limit, 1), self::MAX_RANKING_LIMIT);

        // The repository selects no names at all, so identity never reaches the
        // aggregate rather than being dropped on the way out (#177).
        $rows = $this->repository->memberRanking(
            ReportFilters::fromRequest($dateFrom, $dateTo),
            $limit,
        );

        $data = [];
        $rank = 1;
        foreach ($rows as $row) {
            $data[] = [
                'rank' => $rank,
                'member_name' => "Member {$rank}",
                'total_amount_cents' => $row['total_amount_cents'],
                'transaction_count' => $row['transaction_count'],
            ];
            $rank++;
        }

        return ['data' => $data];
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
