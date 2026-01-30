<?php

use App\Http\Modules\Settlements\Controllers\AdminController;
use App\Http\Modules\Settlements\Controllers\SepaConfigController;
use Illuminate\Support\Facades\Route;

/**
 * Settlements Module Routes - Admin API
 *
 * All routes require admin session authentication
 * Pattern 009: Module Structure with Self-Contained Routes
 *
 * Endpoints:
 * - POST /api/admin/settlements/preview - Preview settlement before creation
 * - POST /api/admin/settlements - Create settlement (SEPA or manual)
 * - GET /api/admin/settlements - List settlements (paginated, filterable)
 * - GET /api/admin/settlements/{id} - Get settlement details with items
 * - DELETE /api/admin/settlements/{id} - Cancel settlement
 * - GET /api/admin/settlements/{id}/export-sepa - Export settlement as SEPA XML
 * - GET /api/admin/settlements/{id}/export-csv - Export settlement as CSV (aggregated by member)
 * - GET /api/admin/settlements/{id}/export-transactions - Export detailed transactions CSV
 * - GET /api/admin/sepa-config - Get SEPA configuration (masked sensitive fields)
 * - PUT /api/admin/sepa-config - Update SEPA configuration
 *
 * Authentication: Admin Session (AuthenticateAdminSession middleware)
 */

Route::prefix('admin')
    ->middleware([
        \App\Http\Middleware\EncryptCookies::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\Session\Middleware\StartSession::class,
        \App\Http\Middleware\AuthenticateAdminSession::class,
    ])
    ->group(function () {
        // Settlement management
        Route::post('/settlements/preview', [AdminController::class, 'preview']);
        Route::post('/settlements', [AdminController::class, 'store']);
        Route::get('/settlements', [AdminController::class, 'index']);
        Route::get('/settlements/{id}', [AdminController::class, 'show']);
        Route::delete('/settlements/{id}', [AdminController::class, 'destroy']);
        Route::get('/settlements/{id}/export-sepa', [AdminController::class, 'exportSepa']);
        Route::get('/settlements/{id}/export-csv', [AdminController::class, 'exportCsv']);
        Route::get('/settlements/{id}/export-transactions', [AdminController::class, 'exportTransactionsCsv']);

        // SEPA configuration
        Route::get('/sepa-config', [SepaConfigController::class, 'show']);
        Route::put('/sepa-config', [SepaConfigController::class, 'update']);
    });
