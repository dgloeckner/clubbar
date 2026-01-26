<?php

use App\Http\Modules\Settlements\Controllers\AdminController;
use App\Http\Modules\Settlements\Controllers\SepaConfigController;
use Illuminate\Support\Facades\Route;

/**
 * Settlements Module Routes - Admin API
 *
 * All routes require admin session authentication
 * Pattern 009: Module Structure with Self-Contained Routes
 */

Route::middleware(['auth:admin'])->group(function () {
    // Settlement management
    Route::post('/settlements/preview', [AdminController::class, 'preview']);
    Route::post('/settlements', [AdminController::class, 'store']);
    Route::get('/settlements', [AdminController::class, 'index']);
    Route::get('/settlements/{id}', [AdminController::class, 'show']);
    Route::delete('/settlements/{id}', [AdminController::class, 'destroy']);
    Route::get('/settlements/{id}/export-sepa', [AdminController::class, 'exportSepa']);
    Route::get('/settlements/{id}/export-csv', [AdminController::class, 'exportCsv']);

    // SEPA configuration
    Route::get('/sepa-config', [SepaConfigController::class, 'show']);
    Route::put('/sepa-config', [SepaConfigController::class, 'update']);
});
