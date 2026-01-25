<?php

namespace App\Http\Modules\Members\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Modules\Members\Requests\AdminListRequest;
use App\Http\Modules\Members\Requests\CreateCorrectionRequest;
use App\Http\Modules\Members\Requests\CreateMemberRequest;
use App\Http\Modules\Members\Requests\ExportTransactionsRequest;
use App\Http\Modules\Members\Requests\UpdateMemberRequest;
use App\Http\Modules\Members\Services\MembersService;
use App\Services\TransactionService;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Services\AuditService;
use Illuminate\Http\JsonResponse;

/**
 * Admin API Controller - Members Module
 *
 * Handles admin endpoints for member management.
 * Implements CRUD operations and GDPR workflows.
 *
 * Implements Pattern 006: Thin Controllers
 * - Only HTTP routing and request/response handling
 * - All business logic delegated to MembersService
 * - Request validation via FormRequest (Pattern 001)
 * - Response serialization via DTOs (Pattern 003)
 *
 * Endpoints:
 * - GET /api/admin/members - List members (paginated, filterable)
 * - POST /api/admin/members - Create member
 * - GET /api/admin/members/{memberId} - View member detail
 * - PATCH /api/admin/members/{memberId} - Update member
 * - DELETE /api/admin/members/{memberId} - Delete member
 * - POST /api/admin/members/{memberId}/export - GDPR export
 * - POST /api/admin/members/{memberId}/anonymize - GDPR anonymization
 */
class AdminController extends Controller
{
    /**
     * Initialize controller with Members service.
     *
     * Service is injected by Laravel's service container.
     * Service is bound in AppServiceProvider.
     *
     * @param MembersService $membersService
     * @param TransactionService $transactionService
     * @param AuditService $auditService
     */
    public function __construct(
        private readonly MembersService $membersService,
        private readonly TransactionService $transactionService,
        private readonly AuditService $auditService,
    ) {}

    /**
     * GET /api/admin/members - List Members (Paginated)
     *
     * Returns paginated list of members with optional filters.
     *
     * Query Parameters:
     * - limit: int (1-100, default 20)
     * - offset: int (default 0)
     * - filters[is_active]: bool (optional)
     * - filters[language]: string (optional, de|en|fr)
     *
     * @param AdminListRequest $request Validated request
     * @return JsonResponse
     */
    public function index(AdminListRequest $request): JsonResponse
    {
        // Get pagination parameters
        $limit = $request->limit();
        $offset = $request->offset();
        $filters = $request->filters();

        // Delegate to service (Pattern 004: Service Layer)
        $result = $this->membersService->listMembers($limit, $offset, $filters);

        // Serialize result to JSON (Pattern 003: DTOs)
        return response()->json($result->toArray());
    }

    /**
     * POST /api/admin/members - Create Member
     *
     * Creates a new member with provided data.
     *
     * @param CreateMemberRequest $request Validated request
     * @return JsonResponse
     */
    public function store(CreateMemberRequest $request): JsonResponse
    {
        // Get validated data
        $firstName = $request->firstName();
        $lastName = $request->lastName();
        $email = $request->email();
        $phone = $request->phone();
        $cardUid = $request->cardUid();
        $language = $request->preferredLanguage();

        // Delegate to service (Pattern 004: Service Layer)
        $member = $this->membersService->createMember(
            $firstName,
            $lastName,
            $email,
            $phone,
            $cardUid,
            $language
        );

        // Return created member as JSON (Pattern 003: DTO serialization)
        return response()->json($member->toArray(), 201);
    }

    /**
     * GET /api/admin/members/{memberId} - View Member Detail
     *
     * Returns a single member with all admin fields.
     *
     * @param string $memberId UUID of member
     * @return JsonResponse
     */
    public function show(string $memberId): JsonResponse
    {
        try {
            // Delegate to service (Pattern 004: Service Layer)
            $member = $this->membersService->getMember($memberId);

            // Return member as JSON (Pattern 003: DTO serialization)
            return response()->json($member->toArray());
        } catch (\Exception $e) {
            // Member not found
            return response()->json(
                ['error' => 'not_found', 'message' => "Member not found: $memberId"],
                404
            );
        }
    }

    /**
     * PATCH /api/admin/members/{memberId} - Update Member
     *
     * Updates specified member fields.
     *
     * @param UpdateMemberRequest $request Validated request
     * @param string $memberId UUID of member to update
     * @return JsonResponse
     */
    public function update(UpdateMemberRequest $request, string $memberId): JsonResponse
    {
        try {
            // Prepare update data from validated input
            $updateData = array_filter([
                'firstName' => $request->firstName(),
                'lastName' => $request->lastName(),
                'email' => $request->email(),
                'phone' => $request->phone(),
                'cardUid' => $request->cardUid(),
                'preferredLanguage' => $request->preferredLanguage()?->value,
            ], fn($value) => $value !== null);

            // Delegate to service (Pattern 004: Service Layer)
            $member = $this->membersService->updateMember($memberId, $updateData);

            // Return updated member as JSON (Pattern 003: DTO serialization)
            return response()->json($member->toArray());
        } catch (\Exception $e) {
            // Member not found
            return response()->json(
                ['error' => 'not_found', 'message' => "Member not found: $memberId"],
                404
            );
        }
    }

    /**
     * DELETE /api/admin/members/{memberId} - Delete Member
     *
     * Hard-deletes a member record.
     *
     * @param string $memberId UUID of member to delete
     * @return JsonResponse
     */
    public function destroy(string $memberId): JsonResponse
    {
        try {
            // Delegate to service (Pattern 004: Service Layer)
            $this->membersService->deleteMember($memberId);

            // Return success response (204 No Content, or 200 with empty JSON)
            return response()->json(['message' => 'Member deleted'], 200);
        } catch (\Exception $e) {
            // Member not found
            return response()->json(
                ['error' => 'not_found', 'message' => "Member not found: $memberId"],
                404
            );
        }
    }

    /**
     * POST /api/admin/members/{memberId}/export - GDPR Export
     *
     * Exports member data including transactions and bookings.
     *
     * @param string $memberId UUID of member to export
     * @return JsonResponse
     */
    public function export(string $memberId): JsonResponse
    {
        try {
            // Delegate to service (Pattern 004: Service Layer)
            $export = $this->membersService->exportMember($memberId);

            // Return export as JSON
            return response()->json($export);
        } catch (\Exception $e) {
            // Member not found
            return response()->json(
                ['error' => 'not_found', 'message' => "Member not found: $memberId"],
                404
            );
        }
    }

    /**
     * POST /api/admin/members/{memberId}/anonymize - GDPR Anonymization
     *
     * Anonymizes member data (GDPR Art. 17 - Right to be forgotten).
     * Soft-deletes member and clears personal identifying information.
     *
     * @param string $memberId UUID of member to anonymize
     * @return JsonResponse
     */
    public function anonymize(string $memberId): JsonResponse
    {
        try {
            // Delegate to service (Pattern 004: Service Layer)
            $member = $this->membersService->anonymizeMember($memberId);

            // Return anonymized member as JSON
            return response()->json($member->toArray());
        } catch (\Exception $e) {
            // Member not found
            return response()->json(
                ['error' => 'not_found', 'message' => "Member not found: $memberId"],
                404
            );
        }
    }

    /**
     * Record Manual Correction Transaction
     *
     * Creates a manual booking (correction transaction) for accounting adjustments.
     * Implements UC-A21: Manual Booking
     *
     * POST /api/admin/members/{memberId}/transactions/correct
     *
     * @param string $memberId Member UUID
     * @param CreateCorrectionRequest $request Validated request
     * @return JsonResponse
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
            $transaction = $this->transactionService->recordCorrection(
                memberId: $memberId,
                amountCents: $validated['amount_cents'],
                reason: $validated['reason'],
                adminId: $adminId
            );

            // Log audit entry
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

            return response()->json(
                ['error' => 'server_error', 'message' => $e->getMessage()],
                500
            );
        }
    }

    /**
     * Export Transactions as CSV
     *
     * Generates and downloads a CSV file of transactions within a date range.
     * Implements UC-A22: Export Transactions
     *
     * GET /api/admin/transactions/export?from_date=YYYY-MM-DD&to_date=YYYY-MM-DD
     *
     * @param ExportTransactionsRequest $request Validated request
     * @return Response CSV file download
     */
    public function exportTransactions(ExportTransactionsRequest $request)
    {
        try {
            $filters = $request->getFilters();

            // Delegate to service (Pattern 004: Service Layer)
            $export = $this->transactionService->exportTransactions(
                fromDate: $filters['from_date'],
                toDate: $filters['to_date'],
                memberId: $filters['member_id'],
                productId: $filters['product_id'],
                type: $filters['type']
            );

            // Log audit entry
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

            // Return CSV as file download
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
