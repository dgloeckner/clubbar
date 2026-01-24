<?php

namespace App\Http\Modules\Members\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Modules\Members\Requests\SyncRequest;
use App\Http\Modules\Members\Requests\UpdateLanguageRequest;
use App\Http\Modules\Members\Services\MembersService;
use Illuminate\Http\JsonResponse;

/**
 * Terminal API Controller - Members Module
 *
 * Handles Member-related Terminal API endpoints.
 *
 * Implements Pattern 006: Thin Controllers
 * - Only HTTP routing and request/response handling
 * - All business logic delegated to MembersService
 * - Request validation via FormRequest (Pattern 001)
 * - Response serialization via DTOs (Pattern 003)
 *
 * Endpoints (Pattern 012: Terminal API Token Authentication):
 * - GET /api/sync/members - Delta sync members
 * - PATCH /api/sync/members/{memberId}/language - Update language preference
 */
class SyncController extends Controller
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
     * GET /api/sync/members - Members Delta Sync
     *
     * Returns members modified since `since` parameter.
     * Used by Terminal app for offline delta sync.
     *
     * Terminal API Specification:
     * - Method: GET
     * - Path: /api/sync/members
     * - Auth: Bearer token (Pattern 012)
     * - Query Params: since (Unix timestamp)
     * - Response: MemberDeltaResponse (members[], cursor, count, has_more)
     *
     * @param SyncRequest $request Validated request
     * @return JsonResponse
     */
    public function index(SyncRequest $request): JsonResponse
    {
        // Get validated 'since' parameter as DateTimeImmutable, convert to Unix timestamp
        $sinceDateTime = $request->since();
        $since = (int) $sinceDateTime->format('U');

        // Delegate to service (Pattern 004: Service Layer)
        $result = $this->membersService->syncSince($since);

        // Serialize result to JSON (Pattern 003: DTOs)
        return response()->json($result->toResponse('members'));
    }

    /**
     * PATCH /api/sync/members/{memberId}/language - Update Language Preference
     *
     * Updates a member's preferred language setting.
     * Used by Terminal app to store user's language selection.
     *
     * Terminal API Specification:
     * - Method: PATCH
     * - Path: /api/sync/members/{memberId}/language
     * - Auth: Bearer token (Pattern 012)
     * - Body: { "preferred_language": "de|en|fr" }
     * - Response: Member (single member DTO)
     *
     * @param UpdateLanguageRequest $request Validated request
     * @param string $memberId UUID of member to update
     * @return JsonResponse
     */
    public function updateLanguage(UpdateLanguageRequest $request, string $memberId): JsonResponse
    {
        // Get validated language from request (Pattern 001: Form Request)
        $language = $request->preferredLanguage();

        try {
            // Delegate to service (Pattern 004: Service Layer)
            $member = $this->membersService->updateLanguage($memberId, $language);
        } catch (\Exception $e) {
            // Member not found
            return response()->json(
                ['error' => 'not_found', 'message' => "Member not found: $memberId"],
                404
            );
        }

        // Return updated member as JSON (Pattern 003: DTO serialization)
        return response()->json($member->toArray());
    }
}
