<?php

namespace App\Http\Modules\Transactions\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Modules\Transactions\Requests\UploadTransactionsRequest;
use App\Http\Modules\Transactions\Services\TransactionsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Transactions Module - Terminal API Controller
 *
 * Handles transaction-related endpoints for offline terminals.
 *
 * Implements Pattern 006: Thin Controllers
 * - Only HTTP routing and request/response handling
 * - All business logic delegated to TransactionsService
 * - Request validation via FormRequest (Pattern 001)
 * - Response serialization via DTOs (Pattern 003)
 *
 * Endpoints:
 * - POST /api/sync/transactions - Batch upload transactions
 * - GET /api/terminal/transactions/{memberId} - Transaction history
 *
 * Implements ADR-0023: Terminal Balance State Management
 * Implements ADR-0024: Transaction History Retrieval (online-only)
 */
final class SyncController extends Controller
{
    /**
     * Initialize controller with Transactions service.
     *
     * @param TransactionsService $service
     */
    public function __construct(
        private readonly TransactionsService $service,
    ) {}

    /**
     * POST /api/sync/transactions - Batch upload transactions
     *
     * Accepts batch of up to 100 transactions from offline terminals.
     * Implements Pattern 001 (UploadTransactionsRequest), Pattern 004 (TransactionsService).
     * Implements ADR-0023: Terminal Balance State Management
     *
     * Response includes member_balances object with current balance per member.
     * Uses insertOrIgnore for idempotency: duplicate UUIDs from retries are silently skipped.
     *
     * @param UploadTransactionsRequest $request Validated transaction batch
     * @return JsonResponse
     */
    public function processBatch(UploadTransactionsRequest $request): JsonResponse
    {
        $result = $this->service->processBatch(
            $request->validated('transactions')
        );

        return response()->json($result->toArray(), 201);
    }

    /**
     * GET /api/terminal/transactions/{memberId} - Fetch transaction history
     *
     * Returns recent transaction history for a member.
     * Implements ADR-0024: Transaction History Retrieval (online-only, no cache).
     *
     * Query Parameters:
     * - limit: Max transactions to return (default 50, max 100)
     * - offset: Pagination offset (default 0)
     * - since: Transactions after this timestamp (ISO 8601)
     *
     * @param Request $request HTTP request with query parameters
     * @param string $memberId Member UUID
     * @return JsonResponse Transaction list or 404 if member not found
     */
    public function transactionHistory(Request $request, string $memberId): JsonResponse
    {
        try {
            // Get pagination parameters from request
            $limit = min((int) $request->query('limit', 50), 100);
            $offset = max((int) $request->query('offset', 0), 0);
            $since = $request->query('since');

            $result = $this->service->getRecentTransactions(
                $memberId,
                $limit,
                $offset,
                $since
            );

            return response()->json($result);
        } catch (\Exception $e) {
            \Log::error('TransactionHistory error: ' . $e->getMessage(), [
                'member_id' => $memberId,
                'exception' => get_class($e),
                'code' => $e->getCode(),
            ]);

            if ($e->getCode() === 404) {
                return response()->json([
                    'error' => 'not_found',
                    'message' => 'Member not found',
                ], 404);
            }

            return response()->json([
                'error' => 'server_error',
                'message' => 'Unable to fetch transaction history: ' . $e->getMessage(),
            ], 500);
        }
    }
}
