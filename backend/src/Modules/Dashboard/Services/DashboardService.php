<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services;

use App\Modules\Dashboard\DTOs\DashboardDto;
use App\Modules\Dashboard\Repositories\DashboardRepository;
use App\Modules\Members\Repositories\MembersRepository;
use App\Modules\Settlements\Repositories\SettlementsRepository;
use App\Modules\Terminals\Repositories\TerminalsRepository;
use App\Modules\Transactions\Repositories\TransactionsRepository;

/**
 * Everything the dashboard and the monthly statistics page mean, as opposed to
 * everything they read (Pattern 004).
 *
 * The judgement calls that used to sit in the HTTP layer — when a terminal
 * counts as online, when missing SEPA data is a warning rather than an error,
 * which of a product's translated names to show — live here, where they can be
 * exercised without a request and without a database.
 */
class DashboardService
{
    /** A terminal that has not synced within this many seconds reads as offline. */
    public const TERMINAL_ONLINE_WINDOW_SECONDS = 300;

    /** Above this many members without a mandate the SEPA alert turns from warning to error. */
    public const SEPA_WARNING_THRESHOLD = 5;

    private const RECENT_TRANSACTION_LIMIT = 10;
    private const REVENUE_WINDOW_DAYS = 30;
    private const TOP_LIST_LIMIT = 10;

    public function __construct(
        private DashboardRepository $dashboardRepository,
        private MembersRepository $membersRepository,
        private TransactionsRepository $transactionsRepository,
        private SettlementsRepository $settlementsRepository,
        private TerminalsRepository $terminalsRepository,
    ) {}

    public function getDashboard(): DashboardDto
    {
        $totalMembers = $this->membersRepository->count();
        $activeMembers = $this->membersRepository->countActive();
        $recentTransactionCount = $this->transactionsRepository->countRecentTransactions(days: self::REVENUE_WINDOW_DAYS);
        $pendingSettlements = $this->settlementsRepository->countPending();

        // Revenue: today, week-to-date (Monday), month-to-date (1st)
        $todaysRevenueCents = $this->dashboardRepository->sumRevenueSince(date('Y-m-d'));
        $wtdRevenueCents = $this->dashboardRepository->sumRevenueSince(date('Y-m-d', strtotime('monday this week')));
        $mtdRevenueCents = $this->dashboardRepository->sumRevenueSince(date('Y-m-01'));

        $latestSettlement = $this->settlementsRepository->getLatest();
        $outstandingBalanceCents = $this->transactionsRepository->sumUnsettledAmountCents();

        $recentTransactions = array_map(
            fn(array $row): array => $this->presentRecentTransaction($row),
            $this->dashboardRepository->findRecentTransactions(self::RECENT_TRANSACTION_LIMIT),
        );

        $terminalRows = $this->terminalsRepository->findAll();
        $now = time();
        $terminalStatus = array_map(
            fn(array $terminal): array => $this->presentTerminal($terminal, $now),
            $terminalRows,
        );

        $sepaIssueCount = $this->dashboardRepository->countMembersWithoutMandate();

        return new DashboardDto(
            metrics: [
                'active_members' => $activeMembers,
                'inactive_members' => $totalMembers - $activeMembers,
                'outstanding_balance_cents' => $outstandingBalanceCents,
                'todays_revenue_cents' => $todaysRevenueCents,
                'wtd_revenue_cents' => $wtdRevenueCents,
                'mtd_revenue_cents' => $mtdRevenueCents,
                'terminal_count' => count($terminalRows),
                'active_terminals' => $this->terminalsRepository->countActive(),
                'settled_members' => 0,
                'sepa_issue_count' => $sepaIssueCount,
            ],
            recentTransactions: $recentTransactions,
            terminalStatus: $terminalStatus,
            systemStatus: [
                'last_settlement_date' => $latestSettlement['created_at'] ?? null,
                'pending_settlement_count' => $pendingSettlements,
                'total_members' => $totalMembers,
                'total_transactions' => $recentTransactionCount,
                'database_health' => 'ok',
            ],
            alerts: ['sepa_issues' => self::sepaAlert($sepaIssueCount)],
        );
    }

    /**
     * @param string $month `YYYY-MM`; callers validate the shape before asking.
     * @return array<string, mixed>
     */
    public function getMonthlyStats(string $month): array
    {
        $startDate = $month . '-01';
        $endDate = date('Y-m-t', strtotime($startDate)); // Last day of month

        $topProductRows = $this->dashboardRepository->findTopProductsBySoldCount($startDate, $endDate, 1);
        $topProduct = null;
        if ($topProductRows !== []) {
            $topProduct = [
                'name' => self::displayName($topProductRows[0]['names']) ?? 'Unknown',
                'sold_count' => (int) $topProductRows[0]['sold_count'],
            ];
        }

        $dailyRevenue = array_map(static fn(array $row): array => [
            'date' => $row['date'],
            'revenue_cents' => (int) $row['revenue_cents'],
            'transaction_count' => (int) $row['transaction_count'],
        ], $this->dashboardRepository->findDailyRevenue($startDate, $endDate));

        $topProducts = array_map(static fn(array $row): array => [
            'id' => $row['id'],
            'name' => self::displayName($row['names']) ?? 'Unknown',
            'sold_count' => (int) $row['sold_count'],
            'revenue_cents' => (int) $row['revenue_cents'],
        ], $this->dashboardRepository->findTopProductsByRevenue($startDate, $endDate, self::TOP_LIST_LIMIT));

        $topMembers = array_map(static fn(array $row): array => [
            'id' => $row['id'],
            'name' => $row['name'],
            'purchase_count' => (int) $row['purchase_count'],
            'revenue_cents' => (int) $row['revenue_cents'],
        ], $this->dashboardRepository->findTopMembers($startDate, $endDate, self::TOP_LIST_LIMIT));

        return [
            'month' => $month,
            'total_revenue_cents' => $this->dashboardRepository->sumRevenueBetween($startDate, $endDate),
            'total_sold_items' => $this->dashboardRepository->countPurchasesBetween($startDate, $endDate),
            'top_product' => $topProduct,
            'daily_revenue' => $dailyRevenue,
            'top_products' => $topProducts,
            'top_members' => $topMembers,
        ];
    }

    /**
     * A terminal is `disabled` when switched off, `online` when it synced inside
     * the window, and `offline` otherwise — including when it has never synced.
     *
     * @param array<string, mixed> $terminal
     */
    public static function terminalStatus(array $terminal, int $now): string
    {
        if (!(bool) $terminal['is_active']) {
            return 'disabled';
        }

        $lastSyncAt = $terminal['last_sync_at'] ?? null;
        if ($lastSyncAt === null) {
            return 'offline';
        }

        return ($now - strtotime($lastSyncAt)) <= self::TERMINAL_ONLINE_WINDOW_SECONDS ? 'online' : 'offline';
    }

    /**
     * @return array{count: int, severity: string, message: string}
     */
    public static function sepaAlert(int $count): array
    {
        if ($count === 0) {
            return ['count' => 0, 'severity' => 'none', 'message' => 'No SEPA data issues'];
        }

        return [
            'count' => $count,
            'severity' => $count <= self::SEPA_WARNING_THRESHOLD ? 'warning' : 'error',
            'message' => "{$count} members missing SEPA data",
        ];
    }

    /**
     * Pick a product's display name out of its translation blob: German first,
     * then English, then nothing.
     */
    public static function displayName(?string $namesJson): ?string
    {
        if ($namesJson === null || $namesJson === '') {
            return null;
        }

        $names = json_decode($namesJson, true);
        if (!is_array($names)) {
            return null;
        }

        return $names['de'] ?? $names['en'] ?? null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentRecentTransaction(array $row): array
    {
        return [
            'id' => $row['id'],
            'member_id' => $row['member_id'],
            'member_name' => $row['member_name'],
            'terminal_name' => $row['terminal_name'],
            'type' => $row['type'],
            'amount_cents' => (int) $row['amount_cents'],
            'product_name' => self::displayName($row['product_names']),
            'timestamp' => $row['timestamp'] ? str_replace(' ', 'T', $row['timestamp']) : null,
        ];
    }

    /**
     * @param array<string, mixed> $terminal
     * @return array<string, mixed>
     */
    private function presentTerminal(array $terminal, int $now): array
    {
        return [
            'id' => $terminal['id'],
            'name' => $terminal['name'],
            'device_id' => $terminal['device_id'],
            'is_active' => (bool) $terminal['is_active'],
            'last_sync_at' => $terminal['last_sync_at'] ?? null,
            'status' => self::terminalStatus($terminal, $now),
        ];
    }
}
