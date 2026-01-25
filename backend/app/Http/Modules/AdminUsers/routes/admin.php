<?php

use App\Http\Modules\AdminUsers\Controllers\AdminController;
use Illuminate\Support\Facades\Route;

/**
 * Admin API Routes - Admin Users Module
 *
 * Admin endpoints for admin user management.
 * These routes are included by routes/api.php
 *
 * Implements Pattern 009: Module Structure (ADR-0018)
 *
 * Endpoints:
 * - GET /api/admin/admin-users - List admin users (paginated, filterable)
 * - POST /api/admin/admin-users - Create admin user
 * - GET /api/admin/admin-users/{id} - Get single admin user
 * - PATCH /api/admin/admin-users/{id} - Update admin user
 * - DELETE /api/admin/admin-users/{id} - Deactivate admin user
 * - POST /api/admin/admin-users/{id}/reactivate - Reactivate admin user
 * - POST /api/admin/admin-users/{id}/reset-password - Reset admin password
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
        // Admin users CRUD endpoints
        Route::get('/admin-users', [AdminController::class, 'index']);
        Route::post('/admin-users', [AdminController::class, 'store']);
        Route::get('/admin-users/{id}', [AdminController::class, 'show']);
        Route::patch('/admin-users/{id}', [AdminController::class, 'update']);
        Route::delete('/admin-users/{id}', [AdminController::class, 'destroy']);

        // Special admin operations
        Route::post('/admin-users/{id}/reactivate', [AdminController::class, 'reactivate']);
        Route::post('/admin-users/{id}/reset-password', [AdminController::class, 'resetPassword']);
    });
