<?php

declare(strict_types=1);

namespace App\Modules\Transactions\Controllers;

use App\Modules\Transactions\Services\TransactionsService;
use App\Shared\Exceptions\NotFoundException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class SyncController
{
    private const MAX_BATCH_SIZE = 100;

    public function __construct(
        private TransactionsService $transactionsService,
    ) {}

    public function processBatch(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];
        $transactions = $body['transactions'] ?? null;

        if ($transactions === null || !is_array($transactions) || count($transactions) === 0) {
            return $this->json($response, ['error' => 'invalid_request', 'message' => 'transactions array is required and must not be empty'], 400);
        }

        if (count($transactions) > self::MAX_BATCH_SIZE) {
            return $this->json($response, ['error' => 'invalid_request', 'message' => 'Batch size exceeds maximum of 100'], 400);
        }

        // Validate each transaction's required fields before processing
        $validationErrors = [];
        foreach ($transactions as $index => $tx) {
            $requiredFields = ['member_id', 'product_id', 'amount_cents', 'created_at'];
            foreach ($requiredFields as $field) {
                if (!isset($tx[$field]) || $tx[$field] === '') {
                    $validationErrors[] = [
                        'field' => $field,
                        'message' => "{$field} is required",
                        'transaction_index' => $index,
                    ];
                }
            }
            // Validate amount_cents > 0 when present
            if (isset($tx['amount_cents']) && $tx['amount_cents'] !== '') {
                $amount = (int) $tx['amount_cents'];
                if ($amount <= 0) {
                    $validationErrors[] = [
                        'field' => 'amount_cents',
                        'message' => 'amount_cents must be greater than 0',
                        'transaction_index' => $index,
                    ];
                }
            }
        }

        if (!empty($validationErrors)) {
            return $this->json($response, ['error' => 'validation_failed', 'details' => $validationErrors], 422);
        }

        $result = $this->transactionsService->processBatch($transactions);

        return $this->json($response, $result->toArray(), 201);
    }

    public function transactionHistory(Request $request, Response $response, array $args): Response
    {
        $memberId = $args['memberId'];
        $params = $request->getQueryParams();
        $limit = (int) ($params['limit'] ?? 50);
        $offset = (int) ($params['offset'] ?? 0);
        $since = $params['since'] ?? null;

        // Check member exists first
        try {
            $transactions = $this->transactionsService->getRecentTransactionsForMember($memberId, $limit, $offset, $since);
        } catch (NotFoundException $e) {
            return $this->json($response, ['error' => 'not_found', 'message' => $e->getMessage()], 404);
        }

        return $this->json($response, [
            'member_id' => $memberId,
            'count' => count($transactions),
            'transactions' => $transactions,
        ]);
    }

    private function json(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
