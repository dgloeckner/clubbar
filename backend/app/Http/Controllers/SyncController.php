<?php

namespace App\Http\Controllers;

use App\Http\Requests\SyncRequest;
use App\Http\Requests\UpdateLanguageRequest;
use App\Http\Requests\UploadTransactionsRequest;
use App\Services\SyncService;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;

/**
 * SyncController
 *
 * Thin controller for Terminal API sync endpoints.
 * Implements Pattern 006: Thin Controllers with all patterns integrated.
 *
 * All business logic delegated to Service Layer (SyncService, TransactionService).
 * All responses use DTOs for type safety.
 * All input validation via FormRequest.
 */
final class SyncController extends Controller
{
    public function __construct(
        private readonly SyncService $syncService,
        private readonly TransactionService $transactionService,
    ) {}

    /**
     * GET /api/sync/members - Delta sync members
     *
     * Returns members modified since `since` timestamp (query parameter).
     * Implements Pattern 001 (SyncRequest), Pattern 004 (SyncService), Pattern 003 (DTOs).
     *
     * @param SyncRequest $request
     * @return JsonResponse
     */
    public function members(SyncRequest $request): JsonResponse
    {
        $result = $this->syncService->syncMembers($request->since());
        return response()->json($result->toResponse('members'));
    }

    /**
     * GET /api/sync/categories - Delta sync categories
     *
     * Returns categories modified since `since` timestamp (query parameter).
     *
     * @param SyncRequest $request
     * @return JsonResponse
     */
    public function categories(SyncRequest $request): JsonResponse
    {
        $result = $this->syncService->syncCategories($request->since());
        return response()->json($result->toResponse('categories'));
    }

    /**
     * GET /api/sync/products - Delta sync products
     *
     * Returns products modified since `since` timestamp (query parameter).
     *
     * @param SyncRequest $request
     * @return JsonResponse
     */
    public function products(SyncRequest $request): JsonResponse
    {
        $result = $this->syncService->syncProducts($request->since());
        return response()->json($result->toResponse('products'));
    }

    /**
     * PATCH /api/sync/members/{memberId}/language - Update member language preference
     *
     * Updates member's preferred language to specified value.
     * Implements Pattern 001 (UpdateLanguageRequest), Pattern 002 (Enum), Pattern 003 (DTO).
     *
     * @param UpdateLanguageRequest $request
     * @param string $memberId Member UUID
     * @return JsonResponse
     */
    public function updateLanguage(UpdateLanguageRequest $request, string $memberId): JsonResponse
    {
        // Validate UUID format
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $memberId)) {
            return response()->json([
                'error' => 'not_found',
                'message' => 'Member not found',
            ], 404);
        }

        $member = $this->syncService->updateMemberLanguage(
            $memberId,
            $request->preferredLanguage()
        );

        return response()->json([
            'id' => $member->id,
            'preferred_language' => $member->preferredLanguage,
            'updated_at' => $member->updatedAt->format('Y-m-d\TH:i:s\Z'),
        ]);
    }

    /**
     * POST /api/sync/transactions - Batch upload transactions
     *
     * Accepts batch of up to 100 transactions from offline terminals.
     * Implements Pattern 001 (UploadTransactionsRequest), Pattern 004 (TransactionService).
     * Implements ADR-0023: Terminal Balance State Management
     *
     * Response includes member_balances object with current balance per member.
     *
     * @param UploadTransactionsRequest $request
     * @return JsonResponse
     */
    public function transactions(UploadTransactionsRequest $request): JsonResponse
    {
        $result = $this->transactionService->processBatch(
            $request->validated('transactions')
        );

        return response()->json($result->toArray());
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
     * @param \Illuminate\Http\Request $request
     * @param string $memberId Member UUID
     * @return JsonResponse Transaction list or 404 if member not found
     */
    public function transactionHistory(\Illuminate\Http\Request $request, string $memberId): JsonResponse
    {
        try {
            // Get pagination parameters from request
            $limit = min((int) $request->query('limit', 50), 100);
            $offset = max((int) $request->query('offset', 0), 0);
            $since = $request->query('since');

            $result = $this->transactionService->getRecentTransactions(
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
