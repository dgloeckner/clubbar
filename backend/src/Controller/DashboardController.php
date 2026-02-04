<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\MembersRepository;
use App\Repository\TransactionsRepository;
use App\Repository\SettlementsRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class DashboardController
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

        return $this->json($response, [
            'total_members' => $totalMembers,
            'active_members' => $activeMembers,
            'transactions_last_30_days' => $recentTransactions,
            'revenue_cents_last_30_days' => $totalRevenueCents,
            'pending_settlements' => $pendingSettlements,
        ]);
    }

    private function json(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
