<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Controllers;

use App\Modules\Dashboard\DTOs\DashboardDto;
use App\Modules\Members\Repositories\MembersRepository;
use App\Modules\Transactions\Repositories\TransactionsRepository;
use App\Modules\Settlements\Repositories\SettlementsRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AdminController
{
    public function __construct(
        private MembersRepository $membersRepository,
        private TransactionsRepository $transactionsRepository,
        private SettlementsRepository $settlementsRepository,
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

        // Build dashboard DTO with proper structure
        $dto = new DashboardDto(
            metrics: [
                'active_members' => $activeMembers,
                'inactive_members' => $totalMembers - $activeMembers,
                'outstanding_balance_cents' => $outstandingBalanceCents,
                'todays_revenue_cents' => $this->transactionsRepository->sumRecentAmountCents(days: 1),
                'terminal_count' => 0, // Future: Implement terminal counting
                'active_terminals' => 0, // Future: Implement active terminal counting
                'settled_members' => 0, // Future: Implement settled members counting
                'sepa_issue_count' => 0, // Future: Implement SEPA issue counting
            ],
            recentTransactions: [], // Future: Implement recent transactions
            terminalStatus: [], // Future: Implement terminal status
            systemStatus: [
                'last_settlement_date' => $lastSettlementDate,
                'pending_settlement_count' => $pendingSettlements,
                'total_members' => $totalMembers,
                'total_transactions' => $recentTransactions,
                'database_health' => 'ok',
            ],
            alerts: [], // Future: Implement alerts
        );

        return $this->json($response, $dto->toArray());
    }

    private function json(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
