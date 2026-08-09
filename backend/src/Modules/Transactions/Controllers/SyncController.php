<?php

declare(strict_types=1);

namespace App\Modules\Transactions\Controllers;

use App\Modules\Transactions\Services\TransactionsService;
use App\Modules\Transactions\Sync\TerminalTransactionAllowlist;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Http\JsonResponder;
use App\Shared\Http\ListQuery;
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

        // #191: a terminal with nothing to upload still needs a way to ask what
        // a member owes — after a settlement the tab is zero and no purchase is
        // coming to reveal it. `member_ids` is that ask, and it is the only
        // thing that makes an empty batch a meaningful request.
        $requestedMemberIds = $this->readRequestedMemberIds($body);

        if ($transactions === null || !is_array($transactions)) {
            return $this->json($response, ['error' => 'invalid_request', 'message' => 'transactions array is required and must not be empty'], 400);
        }

        if (count($transactions) === 0 && count($requestedMemberIds) === 0) {
            return $this->json($response, ['error' => 'invalid_request', 'message' => 'transactions array is required and must not be empty'], 400);
        }

        if (count($requestedMemberIds) > self::MAX_BATCH_SIZE) {
            return $this->json($response, ['error' => 'invalid_request', 'message' => 'member_ids exceeds maximum of 100'], 400);
        }

        if (count($transactions) > self::MAX_BATCH_SIZE) {
            return $this->json($response, ['error' => 'invalid_request', 'message' => 'Batch size exceeds maximum of 100'], 400);
        }

        // Everything above judges the *envelope* — a request where nothing was
        // examined at all. That is the only thing a whole-batch 4xx is for
        // (ruling #143 §2). Field-level problems belong to the row that has
        // them: they are judged by TerminalTransactionValidator inside the
        // service and returned per item, so one unstorable row costs one sale
        // instead of the whole upload (#259).
        //
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

        $result = $this->transactionsService->processBatch($rows, $requestedMemberIds);

        return $this->json($response, $result->toArray(), 201);
    }

    /**
     * Read the optional `member_ids` ask off the request body.
     *
     * Anything that is not a non-empty string is dropped rather than reported:
     * this is a read, so a malformed id can only ever cost the caller a balance
     * it did not get. Rejecting the batch over it would refuse an upload of
     * real sales for a bad *query* parameter.
     *
     * @return list<string>
     */
    private function readRequestedMemberIds(array $body): array
    {
        $raw = $body['member_ids'] ?? null;
        if (!is_array($raw)) {
            return [];
        }

        $ids = [];
        foreach ($raw as $id) {
            if (is_string($id) && $id !== '') {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }

    public function transactionHistory(Request $request, Response $response, array $args): Response
    {
        $memberId = $args['memberId'];
        $params = $request->getQueryParams();
        // Clamped, not rejected: this is a terminal talking, and a 400 in the
        // middle of a sync is worse for it than a shorter page. Unclamped,
        // `limit=-1` and `offset=-5` went straight into LIMIT/OFFSET and came
        // back as a SQL error, i.e. a 500 (#117).
        $limit = min(max((int) ($params['limit'] ?? 50), 1), ListQuery::MAX_PER_PAGE);
        $offset = max((int) ($params['offset'] ?? 0), 0);
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
