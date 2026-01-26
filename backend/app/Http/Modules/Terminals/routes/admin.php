<?php

use App\Http\Modules\Terminals\Controllers\AdminController;
use Illuminate\Support\Facades\Route;

/**
 * Admin API Routes - Terminals Module
 *
 * Admin endpoints for terminal device management.
 * These routes are included by routes/api.php
 *
 * Implements Pattern 009: Module Structure (ADR-0018)
 *
 * Endpoints:
 * - GET /api/admin/terminals - List terminals (paginated, filterable)
 * - POST /api/admin/terminals - Create terminal
 * - GET /api/admin/terminals/{id} - Get single terminal
 * - PATCH /api/admin/terminals/{id} - Update terminal
 * - DELETE /api/admin/terminals/{id} - Delete terminal
 * - POST /api/admin/terminals/{id}/rotate-token - Rotate API token
 * - POST /api/admin/terminals/{id}/revoke - Revoke terminal access
 *
 * Authentication: Pattern 013 (Admin Session Authentication)
 * - All routes require admin session authentication (AuthenticateAdminSession middleware)
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
        // Terminal CRUD endpoints
        Route::get('/terminals', [AdminController::class, 'index']);
        Route::post('/terminals', [AdminController::class, 'store']);
        Route::get('/terminals/{id}', [AdminController::class, 'show']);
        Route::patch('/terminals/{id}', [AdminController::class, 'update']);
        Route::delete('/terminals/{id}', [AdminController::class, 'destroy']);

        // Terminal token operations
        Route::post('/terminals/{id}/rotate-token', [AdminController::class, 'rotateToken']);
        Route::post('/terminals/{id}/revoke', [AdminController::class, 'revoke']);
    });
