<?php

namespace App\Http\Controllers;

use App\Http\Requests\SyncRequest;
use App\Http\Requests\UpdateLanguageRequest;
use App\Services\SyncService;
use Illuminate\Http\JsonResponse;

/**
 * SyncController
 *
 * Thin controller for Terminal API sync endpoints.
 * Implements Pattern 006: Thin Controllers with all patterns integrated.
 *
 * All business logic delegated to Service Layer (SyncService).
 * All responses use DTOs for type safety.
 * All input validation via FormRequest.
 *
 * Note: Transaction endpoints have been moved to Transactions module.
 * See: App\Http\Modules\Transactions\Controllers\SyncController
 */
final class SyncController extends Controller
{
    public function __construct(
        private readonly SyncService $syncService,
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

}
