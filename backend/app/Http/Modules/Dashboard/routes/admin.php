<?php

use App\Http\Modules\Dashboard\Controllers\AdminController;
use Illuminate\Support\Facades\Route;

/**
 * Admin API Routes - Dashboard Module
 *
 * Admin endpoints for dashboard metrics and system overview.
 * These routes are included by routes/api.php
 *
 * Implements Pattern 009: Module Structure (ADR-0018)
 *
 * Endpoints:
 * - GET /api/admin/dashboard - Get aggregated dashboard metrics
 *
 * Authentication: Pattern 013 (Admin Session Authentication)
 * - All routes require admin session authentication (AuthenticateAdminSession middleware)
 * - Session is established via POST /api/auth/login endpoint
 *
 * Response:
 * - Metrics (member counts, balances, revenue)
 * - Recent transactions (last 10)
 * - Terminal status
 * - System status
 * - Admin alerts
 */

Route::prefix('admin')
    ->middleware([
        \App\Http\Middleware\EncryptCookies::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\Session\Middleware\StartSession::class,
        \App\Http\Middleware\AuthenticateAdminSession::class,
    ])
    ->group(function () {
        // Dashboard metrics endpoint
        Route::get('/dashboard', [AdminController::class, 'show']);
    });
