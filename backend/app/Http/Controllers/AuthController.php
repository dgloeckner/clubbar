<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AuthController - Admin Authentication Endpoints
 *
 * Implements Pattern 006: Thin Controllers
 * Routes HTTP requests to AuthService for authentication logic.
 * Manages session lifecycle (login, logout, profile).
 *
 * Implements Pattern 013: Admin Session Authentication
 */
class AuthController extends Controller
{
    /**
     * Initialize controller with AuthService.
     *
     * @param AuthService $authService
     */
    public function __construct(
        private readonly AuthService $authService,
    ) {}

    /**
     * POST /api/auth/login - Admin Login
     *
     * Authenticates admin with email and password.
     * Creates secure session cookie on successful authentication.
     *
     * @param LoginRequest $request Validated email and password
     * @return JsonResponse
     */
    public function login(LoginRequest $request): JsonResponse
    {
        // Get validated credentials
        $email = $request->email();
        $password = $request->password();

        // Attempt authentication
        $admin = $this->authService->authenticate($email, $password);

        // Authentication failed (invalid credentials or inactive account)
        if (!$admin) {
            return response()->json(
                ['error' => 'invalid_credentials', 'message' => 'Invalid email or password'],
                401
            );
        }

        // Authentication successful - create session
        $request->session()->regenerate();
        $request->session()->put('admin_id', $admin->id);

        return response()->json([
            'message' => 'Login successful',
            'admin' => [
                'id' => $admin->id,
                'email' => $admin->email,
                'display_name' => $admin->display_name,
                'locale' => $admin->locale,
            ],
        ], 200);
    }

    /**
     * POST /api/auth/logout - Admin Logout
     *
     * Destroys session and logs out admin.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function logout(Request $request): JsonResponse
    {
        // Destroy session
        $request->session()->flush();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logout successful'], 200);
    }

    /**
     * GET /api/auth/profile - Get Current Admin Profile
     *
     * Returns currently logged-in admin's information.
     * Requires active session.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function profile(Request $request): JsonResponse
    {
        // Session middleware ensures admin_id is in session and user is active
        $adminId = $request->session()->get('admin_id');

        if (!$adminId) {
            return response()->json(
                ['error' => 'unauthorized', 'message' => 'Not authenticated'],
                401
            );
        }

        // Get fresh admin data from database
        $admin = $this->authService->getActiveAdmin($adminId);

        if (!$admin) {
            return response()->json(
                ['error' => 'unauthorized', 'message' => 'Admin account not found or inactive'],
                401
            );
        }

        return response()->json([
            'admin' => [
                'id' => $admin->id,
                'email' => $admin->email,
                'display_name' => $admin->display_name,
                'locale' => $admin->locale,
                'last_login_at' => $admin->last_login_at?->format('Y-m-d\TH:i:s\Z'),
            ],
        ], 200);
    }
}
