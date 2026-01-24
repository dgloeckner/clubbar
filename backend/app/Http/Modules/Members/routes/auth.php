<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

/**
 * Admin Authentication Routes
 *
 * Endpoints for admin login, logout, and session management.
 * These routes are included by routes/modules/members.php
 * which is included by routes/api.php
 *
 * Implements Pattern 013: Admin Session Authentication
 *
 * Endpoints:
 * - POST /api/auth/login - Admin login with email/password
 * - POST /api/auth/logout - Admin logout
 * - GET /api/auth/profile - Get current admin profile
 */

Route::prefix('auth')
    ->middleware([
        \App\Http\Middleware\EncryptCookies::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\Session\Middleware\StartSession::class,
    ])
    ->group(function () {
        // Public endpoints (no auth required)
        Route::post('/login', [AuthController::class, 'login']);

        // Protected endpoints (requires admin session authentication)
        Route::middleware([\App\Http\Middleware\AuthenticateAdminSession::class])->group(function () {
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::get('/profile', [AuthController::class, 'profile']);
        });
    });
