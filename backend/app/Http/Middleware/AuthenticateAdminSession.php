<?php

namespace App\Http\Middleware;

use App\Http\Exceptions\UnauthorizedException;
use App\Services\AuthService;
use Closure;
use Illuminate\Http\Request;

/**
 * AuthenticateAdminSession Middleware
 *
 * Validates admin session authentication for admin API endpoints.
 * Attaches authenticated AdminUser model to request for use in controllers.
 *
 * Flow:
 * 1. Extract admin_id from session
 * 2. Call AuthService to verify admin exists and is active
 * 3. Attach admin to request attributes
 * 4. Allow request to proceed or reject with 401
 *
 * Pattern 013: Admin Session Authentication
 * Pattern 007: Centralized Exception Handling (UnauthorizedException)
 *
 * @see \App\Services\AuthService
 */
class AuthenticateAdminSession
{
    /**
     * Handle an incoming request.
     *
     * @param  Request  $request
     * @param  Closure  $next
     * @return mixed
     *
     * @throws UnauthorizedException
     */
    public function handle(Request $request, Closure $next): mixed
    {
        // Extract admin_id from session
        $adminId = $request->session()->get('admin_id');

        if (!$adminId) {
            throw new UnauthorizedException(
                'Admin session not found',
                'admin_not_authenticated',
                401
            );
        }

        // Verify admin exists and is active
        $authService = app(AuthService::class);
        $admin = $authService->getActiveAdmin($adminId);

        if (!$admin) {
            throw new UnauthorizedException(
                'Admin account not found or inactive',
                'admin_not_found_or_inactive',
                401
            );
        }

        // Attach admin to request for use in controllers
        $request->attributes->set('admin', $admin);

        return $next($request);
    }
}
