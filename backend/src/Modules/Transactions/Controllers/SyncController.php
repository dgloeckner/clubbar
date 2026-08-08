<?php

declare(strict_types=1);

namespace App\Modules\Transactions\Controllers;

use App\Modules\Transactions\Services\TransactionsService;
use App\Modules\Transactions\Sync\TerminalTransactionAllowlist;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class SyncController
{
    use JsonResponder;

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
            // `id` is the terminal-generated UUID the idempotency guarantee of
            // ADR-0004 rests on. It was missing from this list (#82), so a batch
            // entry without one reached the insert and was discarded there while
            // the response still called it accepted.
            $requiredFields = ['id', 'member_id', 'product_id', 'amount_cents', 'created_at'];
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

        // #79, ruling #144 §3: rebuild every row from an explicit allowlist.
        // Passing the client array through let a terminal token set
        // `transaction_type`, `related_transaction_id` and
        // `created_by_admin_id` — enough to forge a storno and, since #169 made
        // the UNIQUE index on `related_transaction_id` the arbiter of
        // "stornoable at most once", to permanently block the genuine one.
        // The terminal id comes from the authenticated token, never the payload.
        $terminalId = $request->getAttribute('terminal_id');
        $rows = [];
        foreach ($transactions as $tx) {
            $rows[] = TerminalTransactionAllowlist::build(is_array($tx) ? $tx : [], $terminalId);
        }

        $result = $this->transactionsService->processBatch($rows);

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
}
