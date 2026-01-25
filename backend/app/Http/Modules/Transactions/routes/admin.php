<?php

use App\Http\Modules\Transactions\Controllers\AdminController;
use Illuminate\Support\Facades\Route;

/**
 * Transactions Module - Admin API Routes
 *
 * Routes for admin transaction management operations.
 * Pattern 013: Admin Session Authentication - Session cookie required
 *
 * These routes are included by routes/modules/transactions.php
 * which is included by routes/api.php
 *
 * Implements Pattern 009: Module Structure & Organization
 * Implements ADR-0018: Modular Admin Interface Architecture
 * Implements UC-A21: Manual Booking
 * Implements UC-A22: Export Transactions
 *
 * Endpoints:
 * - POST /api/admin/members/{memberId}/transactions/correct - Record correction
 * - GET /api/admin/transactions/export - Export as CSV
 *
 * Authentication: Pattern 013 (Admin Session Authentication)
 * - All routes require admin session authentication
 * - Session is established via POST /api/auth/login endpoint
 */

Route::prefix('admin')
    ->middleware([
        \App\Http\Middleware\EncryptCookies::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\Session\Middleware\StartSession::class,
        \App\Http\Middleware\AuthenticateAdminSession::class,
    ])
    ->group(function () {
        /**
         * POST /api/admin/members/{memberId}/transactions/correct
         * Record manual correction transaction for a member
         * Requires: Admin session authentication (Pattern 013)
         * Body: { amount_cents: int (non-zero), reason: string (max 255) }
         * Implements UC-A21: Manual Booking
         */
        Route::post('/members/{memberId}/transactions/correct', [AdminController::class, 'recordCorrection']);

        /**
         * GET /api/admin/transactions/export
         * Export transactions as CSV file
         * Requires: Admin session authentication (Pattern 013)
         * Query params:
         *   - from_date: YYYY-MM-DD (required)
         *   - to_date: YYYY-MM-DD (required, >= from_date)
         *   - member_id: UUID (optional)
         *   - product_id: UUID (optional)
         *   - type: purchase|correction|all (optional)
         * Implements UC-A22: Export Transactions
         */
        Route::get('/transactions/export', [AdminController::class, 'exportTransactions']);
    });
