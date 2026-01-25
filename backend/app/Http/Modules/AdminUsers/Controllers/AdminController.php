<?php

namespace App\Http\Modules\AdminUsers\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Modules\AdminUsers\Requests\ChangePasswordRequest;
use App\Http\Modules\AdminUsers\Requests\CreateAdminUserRequest;
use App\Http\Modules\AdminUsers\Requests\UpdateAdminUserRequest;
use App\Http\Modules\AdminUsers\Services\AdminUsersService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin Users API Controller - Admin User Management
 *
 * Handles admin endpoints for admin user management.
 * Implements CRUD operations with audit logging.
 *
 * Implements Pattern 006: Thin Controllers
 * - Only HTTP routing and request/response handling
 * - All business logic delegated to services
 * - Request validation via FormRequest (Pattern 001)
 * - Response serialization via DTOs (Pattern 003)
 *
 * Endpoints:
 * - GET /api/admin/admin-users - List admin users
 * - POST /api/admin/admin-users - Create admin user
 * - GET /api/admin/admin-users/{id} - Get single admin user
 * - PATCH /api/admin/admin-users/{id} - Update admin user
 * - DELETE /api/admin/admin-users/{id} - Deactivate admin user
 * - POST /api/admin/admin-users/{id}/reactivate - Reactivate admin user
 * - POST /api/admin/admin-users/{id}/reset-password - Reset admin password
 * - PATCH /api/auth/change-password - Change own password
 */
class AdminController extends Controller
{
    /**
     * Initialize controller with AdminUsersService.
     *
     * @param AdminUsersService $adminUsersService
     */
    public function __construct(
        private readonly AdminUsersService $adminUsersService,
    ) {}

    /**
     * GET /api/admin/admin-users - List Admin Users
     *
     * Returns paginated list of admin users with optional filtering.
     *
     * Query Parameters:
     * - page: Page number (default 1)
     * - per_page: Results per page (default 20)
     * - status: 'active'|'inactive'|'all' (default 'all')
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 20);
        $status = $request->query('status', 'all');

        $filters = ['status' => $status];
        $result = $this->adminUsersService->listAdminUsers($page, $perPage, $filters);

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
     * POST /api/admin/admin-users - Create Admin User
     *
     * Creates a new admin user with auto-generated 16-character password.
     * Password is shown once in response; never retrievable after.
     *
     * @param CreateAdminUserRequest $request Validated request
     * @return JsonResponse
     */
    public function store(CreateAdminUserRequest $request): JsonResponse
    {
        $currentAdminId = $request->session()->get('admin_id');

        $result = $this->adminUsersService->createAdminUser(
            email: $request->email(),
            displayName: $request->displayName(),
            locale: $request->locale(),
            currentAdminId: $currentAdminId,
        );

        return response()->json([
            'admin' => $result['admin']->toArray(),
            'password' => $result['password'],
            'message' => 'Admin user created successfully. Password shown above.',
        ], 201);
    }

    /**
     * GET /api/admin/admin-users/{id} - Get Single Admin User
     *
     * Returns single admin user by ID.
     *
     * @param string $id Admin user UUID
     * @return JsonResponse
     */
    public function show(string $id): JsonResponse
    {
        $admin = $this->adminUsersService->findAdminUserById($id);

        if (!$admin) {
            return response()->json(['error' => 'not_found', 'message' => 'Admin user not found'], 404);
        }

        return response()->json(['admin' => $admin->toArray()]);
    }

    /**
     * PATCH /api/admin/admin-users/{id} - Update Admin User
     *
     * Updates admin user (email, display_name, locale).
     * Only provided fields are updated (partial update).
     *
     * @param string $id Admin user UUID
     * @param UpdateAdminUserRequest $request Validated request
     * @return JsonResponse
     */
    public function update(string $id, UpdateAdminUserRequest $request): JsonResponse
    {
        $currentAdminId = $request->session()->get('admin_id');
        $validated = array_filter($request->validated(), fn($v) => $v !== null);

        if (empty($validated)) {
            return response()->json(['error' => 'validation_failed', 'message' => 'No fields to update'], 422);
        }

        $admin = $this->adminUsersService->updateAdminUser(
            id: $id,
            validated: $validated,
            currentAdminId: $currentAdminId,
        );

        if (!$admin) {
            return response()->json(['error' => 'not_found', 'message' => 'Admin user not found'], 404);
        }

        return response()->json(['admin' => $admin->toArray()]);
    }

    /**
     * DELETE /api/admin/admin-users/{id} - Deactivate Admin User
     *
     * Deactivates admin user (sets is_active = false).
     * Enforces business rules:
     * - Cannot deactivate own account
     * - Cannot deactivate last active admin
     *
     * @param string $id Admin user UUID
     * @param Request $request For accessing session admin ID
     * @return JsonResponse
     */
    public function destroy(string $id, Request $request): JsonResponse
    {
        $currentAdminId = $request->session()->get('admin_id');

        try {
            $admin = $this->adminUsersService->deactivateAdminUser($id, $currentAdminId);
            return response()->json(['admin' => $admin->toArray()]);
        } catch (\Exception $e) {
            return response()->json(
                ['error' => 'business_rule_violation', 'message' => $e->getMessage()],
                409
            );
        }
    }

    /**
     * POST /api/admin/admin-users/{id}/reactivate - Reactivate Admin User
     *
     * Reactivates a deactivated admin user (sets is_active = true).
     *
     * @param string $id Admin user UUID
     * @param Request $request For accessing session admin ID
     * @return JsonResponse
     */
    public function reactivate(string $id, Request $request): JsonResponse
    {
        $currentAdminId = $request->session()->get('admin_id');

        try {
            $admin = $this->adminUsersService->reactivateAdminUser($id, $currentAdminId);
            return response()->json(['admin' => $admin->toArray()]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'server_error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/admin/admin-users/{id}/reset-password - Reset Admin Password
     *
     * Generates new 16-character random password for admin user.
     * Password is shown once; never retrievable after.
     * Invalidates all sessions for target admin.
     *
     * @param string $id Admin user UUID
     * @param Request $request For accessing session admin ID
     * @return JsonResponse
     */
    public function resetPassword(string $id, Request $request): JsonResponse
    {
        $currentAdminId = $request->session()->get('admin_id');

        try {
            $result = $this->adminUsersService->resetAdminPassword($id, $currentAdminId);

            return response()->json([
                'admin' => $result['admin']->toArray(),
                'password' => $result['password'],
                'message' => 'Admin password reset successfully. New password shown above.',
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'server_error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * PATCH /api/auth/change-password - Change Own Password
     *
     * Allows authenticated admin to change their own password.
     * Validates current password before allowing change.
     * Session remains valid after password change (regenerated).
     *
     * @param ChangePasswordRequest $request Validated request
     * @return JsonResponse
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $adminId = $request->session()->get('admin_id');

        try {
            $this->adminUsersService->changeOwnPassword(
                adminId: $adminId,
                currentPassword: $request->currentPassword(),
                newPassword: $request->newPassword(),
            );

            // Regenerate session to maintain valid session after password change
            $request->session()->regenerate();

            return response()->json([
                'message' => 'Password changed successfully. Your session remains active.',
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'unauthorized', 'message' => $e->getMessage()], 401);
        }
    }
}
