<?php

use App\Http\Modules\AuditLog\Controllers\AdminController;
use Illuminate\Support\Facades\Route;

/**
 * Audit Log Admin Routes
 *
 * Admin endpoints for audit log viewing and filtering.
 * These routes are included by routes/modules/audit-log.php
 * which is included by routes/api.php
 *
 * Implements Pattern 009: Module Structure (ADR-0018)
 *
 * Endpoints:
 * - GET /api/admin/audit-log - List audit log entries (paginated, filterable)
 *
 * Authentication: Pattern 013 (Admin Session Authentication)
 * - All routes require admin session authentication
 * - Session is established via POST /api/auth/login endpoint
 */

// Admin API endpoints
Route::prefix('admin')
    ->middleware([
        \App\Http\Middleware\EncryptCookies::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\Session\Middleware\StartSession::class,
        \App\Http\Middleware\AuthenticateAdminSession::class,
    ])
    ->group(function () {
        // Audit log viewer endpoint
        Route::get('/audit-log', [AdminController::class, 'index']);
    });
