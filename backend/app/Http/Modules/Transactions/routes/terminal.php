<?php

use App\Http\Middleware\AuthenticateTerminalToken;
use App\Http\Modules\Transactions\Controllers\SyncController;
use Illuminate\Support\Facades\Route;

/**
 * Transactions Module - Terminal API Routes
 *
 * Routes for offline terminal sync operations.
 * Pattern 012: Terminal API Token Authentication - Bearer token required
 *
 * These routes are included by routes/modules/transactions.php
 * which is included by routes/api.php
 *
 * Implements Pattern 009: Module Structure & Organization
 * Implements ADR-0018: Modular Admin Interface Architecture
 * Implements ADR-0023: Terminal Balance State Management
 * Implements ADR-0024: Transaction History Retrieval
 *
 * Endpoints:
 * - POST /api/sync/transactions - Batch upload transactions
 * - GET /api/terminal/transactions/{memberId} - Transaction history
 */

Route::middleware([AuthenticateTerminalToken::class])
    ->prefix('sync')
    ->group(function () {
        /**
         * POST /api/sync/transactions
         * Batch upload transactions from offline terminal
         * Requires: AuthenticateTerminalToken middleware (Pattern 012)
         */
        Route::post('/transactions', [SyncController::class, 'processBatch']);
    });

Route::middleware([AuthenticateTerminalToken::class])
    ->prefix('terminal')
    ->group(function () {
        /**
         * GET /api/terminal/transactions/{memberId}
         * Fetch transaction history for a member
         * Requires: AuthenticateTerminalToken middleware (Pattern 012)
         * Query params: limit (1-100, default 50), offset, since (Unix timestamp)
         */
        Route::get('/transactions/{memberId}', [SyncController::class, 'transactionHistory']);
    });
