<?php

namespace App\Http\Modules\Terminals\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Modules\Terminals\Requests\CreateTerminalRequest;
use App\Http\Modules\Terminals\Requests\UpdateTerminalRequest;
use App\Http\Modules\Terminals\Services\TerminalsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Terminals API Controller - Terminal Device Management
 *
 * Handles admin endpoints for terminal device management.
 * Implements CRUD operations with audit logging.
 *
 * Implements Pattern 006: Thin Controllers
 * - Only HTTP routing and request/response handling
 * - All business logic delegated to services
 * - Request validation via FormRequest (Pattern 001)
 * - Response serialization via DTOs (Pattern 003)
 *
 * Endpoints:
 * - GET /api/admin/terminals - List terminals
 * - POST /api/admin/terminals - Create terminal
 * - GET /api/admin/terminals/{id} - Get single terminal
 * - PATCH /api/admin/terminals/{id} - Update terminal
 * - DELETE /api/admin/terminals/{id} - Delete terminal
 * - POST /api/admin/terminals/{id}/rotate-token - Rotate API token
 * - POST /api/admin/terminals/{id}/revoke - Revoke terminal access
 */
class AdminController extends Controller
{
    /**
     * Initialize controller with TerminalsService.
     *
     * @param TerminalsService $terminalsService
     */
    public function __construct(
        private readonly TerminalsService $terminalsService,
    ) {}

    /**
     * GET /api/admin/terminals - List Terminals
     *
     * Returns paginated list of terminals with optional filtering.
     *
     * Query Parameters:
     * - page: Page number (default 1)
     * - per_page: Results per page (default 20)
     * - is_active: true|false (optional, filters by active status)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 20);

        // Parse is_active filter (null = no filter, true/false = filter)
        $isActive = null;
        if ($request->has('is_active')) {
            $isActiveStr = $request->query('is_active');
            if ($isActiveStr === 'true' || $isActiveStr === '1') {
                $isActive = true;
            } elseif ($isActiveStr === 'false' || $isActiveStr === '0') {
                $isActive = false;
            }
        }

        $result = $this->terminalsService->listTerminals($page, $perPage, $isActive);

        return response()->json($result);
    }

    /**
     * POST /api/admin/terminals - Create Terminal
     *
     * Creates a new terminal with auto-generated API token.
     * Token is shown once in response; never retrievable after.
     *
     * @param CreateTerminalRequest $request Validated request
     * @return JsonResponse
     */
    public function store(CreateTerminalRequest $request): JsonResponse
    {
        $currentAdminId = $request->session()->get('admin_id');

        try {
            $result = $this->terminalsService->createTerminal(
                name: $request->name(),
                deviceId: $request->deviceId(),
                adminUserId: $currentAdminId,
            );

            return response()->json([
                'terminal' => $result['terminal']->toArray(),
                'api_token' => $result['plaintext_token'],
                'message' => 'Terminal created successfully. API token shown above (save this token, it will not be shown again).',
            ], 201);
        } catch (\Exception $e) {
            return response()->json(
                ['error' => 'validation_failed', 'message' => $e->getMessage()],
                422
            );
        }
    }

    /**
     * GET /api/admin/terminals/{id} - Get Single Terminal
     *
     * Returns single terminal by ID.
     *
     * @param string $id Terminal UUID
     * @return JsonResponse
     */
    public function show(string $id): JsonResponse
    {
        try {
            $terminal = $this->terminalsService->getTerminal($id);
            return response()->json(['terminal' => $terminal->toArray()]);
        } catch (\Exception $e) {
            if ($e->getCode() === 404) {
                return response()->json(['error' => 'not_found', 'message' => $e->getMessage()], 404);
            }

            return response()->json(['error' => 'server_error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * PATCH /api/admin/terminals/{id} - Update Terminal
     *
     * Updates terminal (name and/or is_active status).
     * Only provided fields are updated (partial update).
     *
     * @param string $id Terminal UUID
     * @param UpdateTerminalRequest $request Validated request
     * @return JsonResponse
     */
    public function update(string $id, UpdateTerminalRequest $request): JsonResponse
    {
        $currentAdminId = $request->session()->get('admin_id');

        try {
            $terminal = $this->terminalsService->updateTerminal(
                terminalId: $id,
                name: $request->name(),
                isActive: $request->isActive(),
                adminUserId: $currentAdminId,
            );

            return response()->json(['terminal' => $terminal->toArray()]);
        } catch (\Exception $e) {
            if ($e->getCode() === 404) {
                return response()->json(['error' => 'not_found', 'message' => $e->getMessage()], 404);
            }

            if ($e->getCode() === 422) {
                return response()->json(['error' => 'validation_failed', 'message' => $e->getMessage()], 422);
            }

            return response()->json(['error' => 'server_error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * DELETE /api/admin/terminals/{id} - Delete Terminal
     *
     * Deletes (soft-deletes) terminal by setting is_active = false.
     * Record is preserved for audit trail.
     *
     * @param string $id Terminal UUID
     * @param Request $request For accessing session admin ID
     * @return JsonResponse
     */
    public function destroy(string $id, Request $request): JsonResponse
    {
        $currentAdminId = $request->session()->get('admin_id');

        try {
            $this->terminalsService->deleteTerminal($id, $currentAdminId);
            return response()->json(['message' => 'Terminal deleted successfully']);
        } catch (\Exception $e) {
            if ($e->getCode() === 404) {
                return response()->json(['error' => 'not_found', 'message' => $e->getMessage()], 404);
            }

            return response()->json(['error' => 'server_error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/admin/terminals/{id}/rotate-token - Rotate Terminal Token
     *
     * Generates new API token and invalidates old token immediately.
     * New token is shown once; never retrievable after.
     *
     * @param string $id Terminal UUID
     * @param Request $request For accessing session admin ID
     * @return JsonResponse
     */
    public function rotateToken(string $id, Request $request): JsonResponse
    {
        $currentAdminId = $request->session()->get('admin_id');

        try {
            $result = $this->terminalsService->rotateToken($id, $currentAdminId);

            return response()->json([
                'terminal' => $result['terminal']->toArray(),
                'api_token' => $result['plaintext_token'],
                'message' => 'API token rotated successfully. New token shown above (save this token, it will not be shown again).',
            ]);
        } catch (\Exception $e) {
            if ($e->getCode() === 404) {
                return response()->json(['error' => 'not_found', 'message' => $e->getMessage()], 404);
            }

            return response()->json(['error' => 'server_error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/admin/terminals/{id}/revoke - Revoke Terminal Access
     *
     * Removes API token and deactivates terminal.
     * Terminal can no longer authenticate.
     *
     * @param string $id Terminal UUID
     * @param Request $request For accessing session admin ID
     * @return JsonResponse
     */
    public function revoke(string $id, Request $request): JsonResponse
    {
        $currentAdminId = $request->session()->get('admin_id');

        try {
            $this->terminalsService->revokeAccess($id, $currentAdminId);
            return response()->json(['message' => 'Terminal access revoked successfully']);
        } catch (\Exception $e) {
            if ($e->getCode() === 404) {
                return response()->json(['error' => 'not_found', 'message' => $e->getMessage()], 404);
            }

            return response()->json(['error' => 'server_error', 'message' => $e->getMessage()], 500);
        }
    }
}
