<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Controllers;

use App\Modules\Dashboard\DTOs\DashboardDto;
use App\Modules\Members\Repositories\MembersRepository;
use App\Modules\Transactions\Repositories\TransactionsRepository;
use App\Modules\Settlements\Repositories\SettlementsRepository;
use App\Modules\Terminals\Repositories\TerminalsRepository;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AdminController
{
    public function __construct(
        private MembersRepository $membersRepository,
        private TransactionsRepository $transactionsRepository,
        private SettlementsRepository $settlementsRepository,
        private TerminalsRepository $terminalsRepository,
        private PDO $db,
    ) {}

    public function show(Request $request, Response $response): Response
    {
        $totalMembers = $this->membersRepository->count();
        $activeMembers = $this->membersRepository->countActive();
        $recentTransactions = $this->transactionsRepository->countRecentTransactions(days: 30);
        $totalRevenueCents = $this->transactionsRepository->sumRecentAmountCents(days: 30);
        $pendingSettlements = $this->settlementsRepository->countPending();

        // Get latest settlement date
        $latestSettlement = $this->settlementsRepository->getLatest();
        $lastSettlementDate = $latestSettlement ? $latestSettlement['created_at'] : null;

        // Calculate outstanding balance (unsettled transactions)
        $outstandingBalanceCents = $this->transactionsRepository->sumUnsettledAmountCents();

        // Get recent transactions (last 10, ordered by created_at DESC)
        $recentTxStmt = $this->db->prepare(
            "SELECT t.id, t.member_id, CONCAT(m.first_name, ' ', m.last_name) as member_name,
                    t.transaction_type as type, t.amount_cents,
                    p.names as product_names, t.created_at as timestamp
             FROM transactions t
             LEFT JOIN members m ON t.member_id = m.id
             LEFT JOIN products p ON t.product_id = p.id
             ORDER BY t.created_at DESC
             LIMIT 10"
        );
        $recentTxStmt->execute();
        $recentTransactionRows = $recentTxStmt->fetchAll();
        $recentTransactionsList = [];
        foreach ($recentTransactionRows as $row) {
            $productName = null;
            if ($row['product_names']) {
                $names = json_decode($row['product_names'], true);
                $productName = $names['de'] ?? $names['en'] ?? null;
            }
            $recentTransactionsList[] = [
                'id' => $row['id'],
                'member_id' => $row['member_id'],
                'member_name' => $row['member_name'],
                'type' => $row['type'],
                'amount_cents' => (int) $row['amount_cents'],
                'product_name' => $productName,
                'timestamp' => $row['timestamp'] ? str_replace(' ', 'T', $row['timestamp']) : null,
            ];
        }

        // Get terminal status
        $terminalRows = $this->terminalsRepository->findAll();
        $terminalStatusList = [];
        foreach ($terminalRows as $terminal) {
            $isActive = (bool) $terminal['is_active'];
            $lastSyncAt = $terminal['last_sync_at'] ?? null;
            if (!$isActive) {
                $status = 'disabled';
            } elseif ($lastSyncAt !== null) {
                $status = 'online';
            } else {
                $status = 'offline';
            }
            $terminalStatusList[] = [
                'id' => $terminal['id'],
                'name' => $terminal['name'],
                'device_id' => $terminal['device_id'],
                'is_active' => $isActive,
                'last_sync_at' => $lastSyncAt,
                'status' => $status,
            ];
        }

        // SEPA alerts
        $sepaIssueCount = (int) $this->db->query(
            "SELECT COUNT(*) FROM members WHERE (iban IS NULL OR mandate_reference IS NULL) AND deleted_at IS NULL"
        )->fetchColumn();
        $sepaAlert = [
            'count' => $sepaIssueCount,
            'severity' => $sepaIssueCount === 0 ? 'none' : ($sepaIssueCount <= 5 ? 'warning' : 'error'),
            'message' => $sepaIssueCount === 0 ? 'No SEPA data issues' : "{$sepaIssueCount} members missing SEPA data",
        ];

        $activeTerminalCount = $this->terminalsRepository->countActive();

        // Build dashboard DTO with proper structure
        $dto = new DashboardDto(
            metrics: [
                'active_members' => $activeMembers,
                'inactive_members' => $totalMembers - $activeMembers,
                'outstanding_balance_cents' => $outstandingBalanceCents,
                'todays_revenue_cents' => $this->transactionsRepository->sumRecentAmountCents(days: 1),
                'terminal_count' => count($terminalRows),
                'active_terminals' => $activeTerminalCount,
                'settled_members' => 0,
                'sepa_issue_count' => $sepaIssueCount,
            ],
            recentTransactions: $recentTransactionsList,
            terminalStatus: $terminalStatusList,
            systemStatus: [
                'last_settlement_date' => $lastSettlementDate,
                'pending_settlement_count' => $pendingSettlements,
                'total_members' => $totalMembers,
                'total_transactions' => $recentTransactions,
                'database_health' => 'ok',
            ],
            alerts: ['sepa_issues' => $sepaAlert],
        );

        return $this->json($response, $dto->toArray());
    }

    /**
     * Get monthly statistics for the statistics page
     */
    public function monthlyStats(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $month = $params['month'] ?? date('Y-m');

        // Validate month format YYYY-MM
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            return $this->json($response, ['error' => 'Invalid month format. Use YYYY-MM.'], 400);
        }

        $startDate = $month . '-01';
        $endDate = date('Y-m-t', strtotime($startDate)); // Last day of month

        // Total revenue for the month
        $stmt = $this->db->prepare(
            'SELECT COALESCE(SUM(amount_cents), 0) FROM transactions WHERE created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY)'
        );
        $stmt->execute([$startDate, $endDate]);
        $totalRevenueCents = (int) $stmt->fetchColumn();

        // Total sold items (count of purchase transactions)
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM transactions WHERE created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY) AND transaction_type = ?'
        );
        $stmt->execute([$startDate, $endDate, 'purchase']);
        $totalSoldItems = (int) $stmt->fetchColumn();

        // Top product
        $stmt = $this->db->prepare(
            "SELECT p.names, COUNT(*) as sold_count
             FROM transactions t
             JOIN products p ON t.product_id = p.id
             WHERE t.created_at >= ? AND t.created_at < DATE_ADD(?, INTERVAL 1 DAY)
               AND t.transaction_type = 'purchase'
             GROUP BY p.id
             ORDER BY sold_count DESC
             LIMIT 1"
        );
        $stmt->execute([$startDate, $endDate]);
        $topProductRow = $stmt->fetch();
        $topProduct = null;
        if ($topProductRow) {
            $names = json_decode($topProductRow['names'], true);
            $topProduct = [
                'name' => $names['de'] ?? $names['en'] ?? 'Unknown',
                'sold_count' => (int) $topProductRow['sold_count'],
            ];
        }

        // Daily revenue
        $stmt = $this->db->prepare(
            "SELECT DATE(created_at) as date,
                    COALESCE(SUM(amount_cents), 0) as revenue_cents,
                    COUNT(*) as transaction_count
             FROM transactions
             WHERE created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY)
             GROUP BY DATE(created_at)
             ORDER BY date"
        );
        $stmt->execute([$startDate, $endDate]);
        $dailyRevenueRows = $stmt->fetchAll();
        $dailyRevenue = [];
        foreach ($dailyRevenueRows as $row) {
            $dailyRevenue[] = [
                'date' => $row['date'],
                'revenue_cents' => (int) $row['revenue_cents'],
                'transaction_count' => (int) $row['transaction_count'],
            ];
        }

        // Top 10 products
        $stmt = $this->db->prepare(
            "SELECT p.id, p.names, COUNT(*) as sold_count, SUM(t.amount_cents) as revenue_cents
             FROM transactions t
             JOIN products p ON t.product_id = p.id
             WHERE t.created_at >= ? AND t.created_at < DATE_ADD(?, INTERVAL 1 DAY)
               AND t.transaction_type = 'purchase'
             GROUP BY p.id
             ORDER BY revenue_cents DESC
             LIMIT 10"
        );
        $stmt->execute([$startDate, $endDate]);
        $topProductsRows = $stmt->fetchAll();
        $topProducts = [];
        foreach ($topProductsRows as $row) {
            $names = json_decode($row['names'], true);
            $topProducts[] = [
                'id' => $row['id'],
                'name' => $names['de'] ?? $names['en'] ?? 'Unknown',
                'sold_count' => (int) $row['sold_count'],
                'revenue_cents' => (int) $row['revenue_cents'],
            ];
        }

        // Top 10 members
        $stmt = $this->db->prepare(
            "SELECT m.id, CONCAT(m.first_name, ' ', m.last_name) as name,
                    COUNT(*) as purchase_count, SUM(t.amount_cents) as revenue_cents
             FROM transactions t
             JOIN members m ON t.member_id = m.id
             WHERE t.created_at >= ? AND t.created_at < DATE_ADD(?, INTERVAL 1 DAY)
               AND t.transaction_type = 'purchase'
             GROUP BY m.id
             ORDER BY revenue_cents DESC
             LIMIT 10"
        );
        $stmt->execute([$startDate, $endDate]);
        $topMembersRows = $stmt->fetchAll();
        $topMembers = [];
        foreach ($topMembersRows as $row) {
            $topMembers[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'purchase_count' => (int) $row['purchase_count'],
                'revenue_cents' => (int) $row['revenue_cents'],
            ];
        }

        return $this->json($response, [
            'month' => $month,
            'total_revenue_cents' => $totalRevenueCents,
            'total_sold_items' => $totalSoldItems,
            'top_product' => $topProduct,
            'daily_revenue' => $dailyRevenue,
            'top_products' => $topProducts,
            'top_members' => $topMembers,
        ]);
    }

    private function json(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
