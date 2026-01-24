<?php

namespace App\Services;

use App\Models\AdminUser;
use Illuminate\Support\Facades\Hash;

/**
 * AuthService - Admin Authentication Logic
 *
 * Implements Pattern 013: Admin Session Authentication
 * Handles credential verification and session management.
 *
 * Responsibilities:
 * - Verify admin credentials (email + password)
 * - Check if admin account is active
 * - Hash and compare passwords using bcrypt
 * - Record login timestamps for audit trail
 */
class AuthService
{
    /**
     * Authenticate admin with email and password.
     *
     * Returns admin user if credentials valid and account active.
     * Returns null if email not found, password invalid, or account inactive.
     *
     * @param string $email Admin email
     * @param string $password Plaintext password
     * @return AdminUser|null Authenticated admin user, or null on failure
     */
    public function authenticate(string $email, string $password): ?AdminUser
    {
        // Find admin by email
        $admin = AdminUser::where('email', $email)->first();

        // Email not found or password doesn't match
        if (!$admin || !Hash::check($password, $admin->password_hash)) {
            return null;
        }

        // Account must be active
        if (!$admin->isActive()) {
            return null;
        }

        // Update last login timestamp for audit trail
        $admin->recordLogin();

        return $admin;
    }

    /**
     * Get admin by ID and verify still active.
     *
     * Used by session middleware to validate the session user still exists
     * and has an active account.
     *
     * @param string $adminId Admin UUID
     * @return AdminUser|null Active admin, or null if not found/inactive
     */
    public function getActiveAdmin(string $adminId): ?AdminUser
    {
        $admin = AdminUser::find($adminId);

        if (!$admin || !$admin->isActive()) {
            return null;
        }

        return $admin;
    }
}
