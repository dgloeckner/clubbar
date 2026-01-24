<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\SyncController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Terminal API endpoints (see api/terminal.yaml for OpenAPI spec)
|
*/

// Health check (no auth required)
Route::get('/health', [HealthController::class, 'health']);

// Sync endpoints (TODO: add auth middleware when Sanctum is configured)
Route::prefix('sync')->group(function () {
    Route::get('/members', [SyncController::class, 'members']);
    Route::get('/categories', [SyncController::class, 'categories']);
    Route::get('/products', [SyncController::class, 'products']);
    Route::patch('/members/{memberId}/language', [SyncController::class, 'updateLanguage']);
    Route::post('/transactions', [SyncController::class, 'transactions']);
});
