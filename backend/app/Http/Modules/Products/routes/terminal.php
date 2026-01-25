<?php

use App\Http\Modules\Products\Controllers\SyncController;
use Illuminate\Support\Facades\Route;

/**
 * Terminal API Routes - Products Module
 *
 * Terminal sync endpoints for products and categories.
 * These routes are included by routes/modules/products.php
 * which is included by routes/api.php
 *
 * Implements Pattern 009: Module Structure (ADR-0018)
 *
 * Terminal Sync Endpoints:
 * - GET /api/sync/categories - Delta sync categories
 * - GET /api/sync/products - Delta sync products
 *
 * Authentication: Pattern 012 (Terminal Device Authentication)
 * - All routes require valid Bearer token (auth.terminal middleware)
 * - Token issued to terminal devices, allows offline-first sync
 */

Route::prefix('sync')
    ->middleware([\App\Http\Middleware\AuthenticateTerminalToken::class])
    ->group(function () {
        Route::get('/categories', [SyncController::class, 'categories']);
        Route::get('/products', [SyncController::class, 'products']);
    });
