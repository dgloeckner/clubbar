<?php

namespace App\Http\Modules\Transactions\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Modules\Transactions\Requests\CreateCorrectionRequest;
use App\Http\Modules\Transactions\Requests\ExportTransactionsRequest;
use App\Http\Modules\Transactions\Requests\GetTransactionsRequest;
use App\Http\Modules\Transactions\Services\TransactionsService;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Transactions Module - Admin API Controller
 *
 * Handles admin-only endpoints for transaction management.
 *
 * Implements Pattern 006: Thin Controllers
 * - Only HTTP routing and request/response handling
 * - All business logic delegated to TransactionsService
 * - Request validation via FormRequest (Pattern 001)
 * - Audit logging for all mutations (Pattern 016)
 *
 * Endpoints:
 * - GET /api/admin/transactions - Global transactions listing
 * - POST /api/admin/members/{memberId}/transactions/correct - Record correction
 * - GET /api/admin/transactions/export - Export to CSV
 *
 * Implements UC-A20: View member transactions
 * Implements UC-A21: Manual Booking
 * Implements UC-A22: Export Transactions
 */
final class AdminController extends Controller
{
    /**
     * Initialize controller with Transactions service and Audit service.
     *
     * @param TransactionsService $service
     * @param AuditService $auditService
     */
    public function __construct(
        private readonly TransactionsService $service,
        private readonly AuditService $auditService,
    ) {}

    /**
     * GET /api/admin/members/{memberId}/transactions - Get member transaction history
     *
     * Retrieves transaction history for a specific member with current balance.
     * Implements UC-A20: View member transactions
     * Implements Pattern 001 (FormRequest), Pattern 004 (Service Layer)
     *
     * Query Parameters:
     * - type: optional, filter by transaction type (all|purchase|correction)
     *
     * @param string $memberId Member UUID
     * @return JsonResponse Member transaction history with current_balance_cents and transactions
     */
    public function getTransactionHistory(string $memberId): JsonResponse
    {
        try {
            $type = request()->query('type');

            // Delegate to service (Pattern 004: Service Layer)
            $history = $this->service->getMemberTransactionHistory($memberId, $type);

            return response()->json($history);
        } catch (\Exception $e) {
            if ($e->getCode() === 404) {
                return response()->json(
                    ['error' => 'not_found', 'message' => $e->getMessage()],
                    404
                );
            }

            return response()->json(
                ['error' => 'server_error', 'message' => $e->getMessage()],
                500
            );
        }
    }

    /**
     * GET /api/admin/transactions - Get global transactions list
     *
     * Retrieves paginated list of all transactions across all members with filtering and sorting.
     * Implements global transaction journal view.
     * Implements Pattern 001 (FormRequest), Pattern 004 (Service Layer)
     * Implements UC-A22-B: Filter Transactions by Settlement Status
     *
     * Query Parameters:
     * - page: integer, 1-indexed (default: 1)
     * - per_page: integer, items per page (default: 20, max: 100)
     * - date_from: ISO date (YYYY-MM-DD), optional
     * - date_to: ISO date (YYYY-MM-DD), optional
     * - type: all|purchase|correction (default: all)
     * - member_id: UUID (optional, filter by member)
     * - search: string (optional, search member name or notes)
     * - sort: created_at|amount|type|member (default: created_at)
     * - order: asc|desc (default: desc)
     * - settlement_status: all|open|settled (default: all)
     *
     * @param GetTransactionsRequest $request Validated query parameters
     * @return JsonResponse Paginated transactions with total count
     */
    public function getTransactions(GetTransactionsRequest $request): JsonResponse
    {
        try {
            $pagination = $request->getPagination();
            $filters = $request->getFilters();
            $sort = $request->getSort();

            // Delegate to service (Pattern 004: Service Layer)
            $result = $this->service->getTransactions(
                page: $pagination['page'],
                perPage: $pagination['per_page'],
                dateFrom: $filters['date_from'],
                dateTo: $filters['date_to'],
                type: $filters['type'],
                memberId: $filters['member_id'],
                search: $filters['search'],
                sortKey: $sort['sort'],
                sortOrder: $sort['order'],
                settlementStatus: $filters['settlement_status']
            );

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json(
                ['error' => 'server_error', 'message' => $e->getMessage()],
                500
            );
        }
    }

    /**
     * POST /api/admin/members/{memberId}/transactions/correct - Record correction
     *
     * Creates a manual booking (correction transaction) for accounting adjustments.
     * Implements UC-A21: Manual Booking
     * Implements Pattern 001 (FormRequest), Pattern 004 (Service Layer), Pattern 016 (Audit Logging)
     *
     * @param string $memberId Member UUID
     * @param CreateCorrectionRequest $request Validated request (amount_cents, reason)
     * @return JsonResponse Created transaction with 201 status
     */
    public function recordCorrection(
        string $memberId,
        CreateCorrectionRequest $request
    ): JsonResponse {
        try {
            $validated = $request->validated();

            // Get admin ID from authenticated user
            $adminId = auth()->id();

            // Delegate to service (Pattern 004: Service Layer)
            $transaction = $this->service->recordCorrection(
                memberId: $memberId,
                amountCents: $validated['amount_cents'],
                reason: $validated['reason'],
                adminId: $adminId
            );

            // Log audit entry (Pattern 016: Audit Logging)
            $this->auditService->log(
                action: AuditAction::CREATE,
                entityType: EntityType::TRANSACTION,
                entityId: $transaction['id'],
                newValues: [
                    'member_id' => $memberId,
                    'amount_cents' => $validated['amount_cents'],
                    'reason' => $validated['reason'],
                ]
            );

            return response()->json($transaction, 201);
        } catch (\Exception $e) {
            if ($e->getCode() === 404) {
                return response()->json(
                    ['error' => 'not_found', 'message' => $e->getMessage()],
                    404
                );
            }

            if ($e->getCode() === 422) {
                return response()->json([
                    'error' => 'sepa_invalid',
                    'message' => $e->getMessage(),
                ], 422);
            }

            return response()->json(
                ['error' => 'server_error', 'message' => $e->getMessage()],
                500
            );
        }
    }

    /**
     * GET /api/admin/transactions/export - Export transactions as CSV
     *
     * Generates and downloads a CSV file of transactions within a date range.
     * Implements UC-A22: Export Transactions
     * Implements Pattern 001 (FormRequest), Pattern 004 (Service Layer), Pattern 016 (Audit Logging)
     *
     * Query Parameters:
     * - from_date: required, YYYY-MM-DD format
     * - to_date: required, YYYY-MM-DD format (>= from_date)
     * - member_id: optional, filter by member UUID
     * - product_id: optional, filter by product UUID
     * - type: optional, filter by transaction type (purchase|correction|all)
     *
     * @param ExportTransactionsRequest $request Validated request with filters
     * @return Response CSV file download with appropriate headers
     */
    public function exportTransactions(ExportTransactionsRequest $request): Response
    {
        try {
            $filters = $request->getFilters();

            // Delegate to service (Pattern 004: Service Layer)
            $export = $this->service->exportTransactions(
                fromDate: $filters['from_date'],
                toDate: $filters['to_date'],
                memberId: $filters['member_id'],
                productId: $filters['product_id'],
                type: $filters['type']
            );

            // Log audit entry (Pattern 016: Audit Logging)
            $this->auditService->log(
                action: AuditAction::EXPORT,
                entityType: EntityType::TRANSACTION,
                entityId: 'batch',
                newValues: [
                    'from_date' => $filters['from_date'],
                    'to_date' => $filters['to_date'],
                    'member_id' => $filters['member_id'],
                    'product_id' => $filters['product_id'],
                    'type' => $filters['type'],
                ]
            );

            // Return CSV as file download with proper headers
            return response($export['csv_content'])
                ->header('Content-Type', 'text/csv; charset=utf-8')
                ->header('Content-Disposition', 'attachment; filename="' . $export['filename'] . '"')
                ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
                ->header('Pragma', 'no-cache')
                ->header('Expires', '0');
        } catch (\Exception $e) {
            return response()->json(
                ['error' => 'server_error', 'message' => $e->getMessage()],
                500
            );
        }
    }
}
