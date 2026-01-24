<?php

use App\Http\Middleware\AuthenticateTerminalToken;
use App\Http\Modules\Members\Controllers\SyncController;
use Illuminate\Support\Facades\Route;

/**
 * Terminal API Routes - Members Module
 *
 * Routes for Terminal app member endpoints.
 * Pattern 012: Terminal API Token Authentication - Bearer token required
 *
 * These routes are included by routes/modules/members.php
 * which is included by routes/api.php
 *
 * Endpoints:
 * - GET /api/sync/members - Delta sync members
 * - PATCH /api/sync/members/{memberId}/language - Update language
 */

Route::middleware([AuthenticateTerminalToken::class])
    ->prefix('sync')
    ->group(function () {
        Route::get('/members', [SyncController::class, 'index']);
        Route::patch('/members/{memberId}/language', [SyncController::class, 'updateLanguage']);
    });
