<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\TransactionsService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class TransactionsSyncController
{
    public function __construct(
        private TransactionsService $transactionsService,
    ) {}

    public function processBatch(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];
        $transactions = $body['transactions'] ?? [];

        if (empty($transactions) || !is_array($transactions)) {
            return $this->json($response, ['error' => 'transactions array is required'], 422);
        }

        $result = $this->transactionsService->processBatch($transactions);

        return $this->json($response, $result->toArray());
    }

    public function transactionHistory(Request $request, Response $response, array $args): Response
    {
        $memberId = $args['memberId'];
        $params = $request->getQueryParams();
        $limit = (int) ($params['limit'] ?? 50);
        $offset = (int) ($params['offset'] ?? 0);
        $since = $params['since'] ?? null;

        $transactions = $this->transactionsService->getRecentTransactions($memberId, $limit, $offset, $since);

        return $this->json($response, ['transactions' => $transactions]);
    }

    private function json(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
