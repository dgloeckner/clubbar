<?php

namespace App\Http\Modules\Settlements\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Modules\Settlements\Requests\CancelSettlementRequest;
use App\Http\Modules\Settlements\Requests\CreateSettlementRequest;
use App\Http\Modules\Settlements\Requests\PreviewSettlementRequest;
use App\Http\Modules\Settlements\Services\SettlementsService;
use App\Shared\Enums\ManualReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * SettlementsAdminController - Settlement Management API
 *
 * Handles all settlement operations: create, preview, list, cancel, and export
 *
 * Implements Pattern 006: Thin Controllers (< 50 lines per method)
 * Implements UC-A30 through UC-A35: Settlement use cases
 */
class AdminController extends Controller
{
    public function __construct(
        private readonly SettlementsService $settlementsService,
    ) {}

    /**
     * POST /api/admin/settlements/preview - Preview Settlement
     *
     * Shows what a settlement would look like before creation
     */
    public function preview(PreviewSettlementRequest $request): JsonResponse
    {
        $preview = $this->settlementsService->previewSettlement(
            fromDate: $request->fromDate(),
            toDate: $request->toDate(),
            memberId: $request->memberId(),
            sepaEligibleOnly: $request->sepaEligibleOnly(),
        );

        return response()->json($preview->toArray(), 200);
    }

    /**
     * POST /api/admin/settlements - Create Settlement
     *
     * Creates a settlement and marks transactions as settled
     */
    public function store(CreateSettlementRequest $request): JsonResponse
    {
        $adminId = $request->session()->get('admin_id');
        $manualReason = $request->manualReason() ? ManualReason::from($request->manualReason()) : null;

        $settlement = $this->settlementsService->createSettlement(
            transactionIds: $request->transactionIds(),
            settlementDate: $request->settlementDate(),
            executionDate: $request->executionDate(),
            periodStart: $request->periodStart(),
            periodEnd: $request->periodEnd(),
            manualReason: $manualReason,
            notes: $request->notes(),
            adminUserId: $adminId,
        );

        return response()->json($settlement->toArray(), 201);
    }

    /**
     * GET /api/admin/settlements - List Settlements
     *
     * Returns paginated list with optional type filtering
     */
    public function index(Request $request): JsonResponse
    {
        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 20);
        $type = $request->query('type');
        $status = $request->query('status', 'all');
        $sortKey = $request->query('sort', 'created_at');
        $sortOrder = $request->query('order', 'desc');

        // Validate parameters
        $validStatuses = ['all', 'active', 'cancelled'];
        $validSortKeys = ['created_at', 'created_by'];
        $validSortOrders = ['asc', 'desc'];

        if (!in_array($status, $validStatuses)) {
            $status = 'all';
        }
        if (!in_array($sortKey, $validSortKeys)) {
            $sortKey = 'created_at';
        }
        if (!in_array($sortOrder, $validSortOrders)) {
            $sortOrder = 'desc';
        }

        $result = $this->settlementsService->listSettlements($page, $perPage, $type, $status, $sortKey, $sortOrder);

        return response()->json([
            'data' => array_map(fn($dto) => $dto->toArray(), $result->items),
            'pagination' => [
                'total' => $result->total,
                'per_page' => $result->limit,
                'current_page' => $page,
                'last_page' => (int) ceil($result->total / $result->limit),
            ],
        ]);
    }

    /**
     * GET /api/admin/settlements/{id} - Get Settlement Details
     *
     * Returns complete settlement with all items
     */
    public function show(string $id): JsonResponse
    {
        $settlement = $this->settlementsService->getSettlement($id);

        if (!$settlement) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return response()->json($settlement->toArray());
    }

    /**
     * DELETE /api/admin/settlements/{id} - Cancel Settlement
     *
     * Cancels a settlement and unmarksall transactions
     */
    public function destroy(string $id, CancelSettlementRequest $request): JsonResponse
    {
        $adminId = $request->session()->get('admin_id');

        try {
            $success = $this->settlementsService->cancelSettlement(
                settlementId: $id,
                adminUserId: $adminId,
                reason: $request->reason(),
            );

            if (!$success) {
                return response()->json(['error' => 'not_found'], 404);
            }

            return response()->json(null, 204);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /api/admin/settlements/{id}/export-sepa - Export SEPA XML
     *
     * Generates and downloads SEPA XML file
     */
    public function exportSepa(string $id, Request $request): StreamedResponse|JsonResponse
    {
        $adminId = $request->session()->get('admin_id');

        try {
            $xml = $this->settlementsService->exportSepaXml($id, $adminId);

            return response()->streamDownload(
                fn() => print $xml,
                "settlement-{$id}.xml",
                ['Content-Type' => 'application/xml; charset=UTF-8'],
            );
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /api/admin/settlements/{id}/export-csv - Export CSV
     *
     * Generates and downloads CSV file for reconciliation
     */
    public function exportCsv(string $id, Request $request): StreamedResponse|JsonResponse
    {
        $adminId = $request->session()->get('admin_id');

        try {
            $csv = $this->settlementsService->exportCsv($id, $adminId);

            return response()->streamDownload(
                fn() => print $csv,
                "settlement-{$id}.csv",
                ['Content-Type' => 'text/csv; charset=UTF-8'],
            );
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /api/admin/settlements/{id}/export-transactions - Export Detailed Transactions CSV
     *
     * Generates and downloads CSV file with detailed transaction information
     */
    public function exportTransactionsCsv(string $id, Request $request): StreamedResponse|JsonResponse
    {
        $adminId = $request->session()->get('admin_id');

        try {
            $csv = $this->settlementsService->exportTransactionsCsv($id, $adminId);

            return response()->streamDownload(
                fn() => print $csv,
                "settlement-{$id}-transactions.csv",
                ['Content-Type' => 'text/csv; charset=UTF-8'],
            );
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }
}
