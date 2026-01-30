<?php

namespace App\Http\Modules\Members\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Modules\Members\Requests\AdminListRequest;
use App\Http\Modules\Members\Requests\CreateMemberRequest;
use App\Http\Modules\Members\Requests\UpdateMemberRequest;
use App\Http\Modules\Members\Services\MembersService;
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
 *
 * Note: Transaction endpoints (manual corrections, exports) have been moved to Transactions module.
 * See: App\Http\Modules\Transactions\Controllers\AdminController
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
     */
    public function __construct(
        private readonly MembersService $membersService,
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
        $search = $request->search();
        $filters = $request->filters();
        $sortKey = $request->sortKey();
        $sortOrder = $request->sortOrder();

        // Delegate to service (Pattern 004: Service Layer)
        $result = $this->membersService->listMembers($limit, $offset, $filters, $sortKey, $sortOrder, $search);

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
        $iban = $request->iban();
        $mandateReference = $request->mandateReference();
        $mandateSignedAt = $request->mandateSignedAt();

        // Delegate to service (Pattern 004: Service Layer)
        $member = $this->membersService->createMember(
            $firstName,
            $lastName,
            $email,
            $phone,
            $cardUid,
            $language,
            $iban,
            $mandateReference,
            $mandateSignedAt
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
                'isActive' => $request->isActive(),
                'iban' => $request->iban(),
                'mandateReference' => $request->mandateReference(),
                'mandateSignedAt' => $request->mandateSignedAt(),
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

}
