<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\SyncController;
use App\Http\Middleware\AuthenticateTerminalToken;
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

// Test POST route
Route::post('/test', function () {
    return response()->json(['message' => 'POST works!'], 200);
});

// Sync endpoints - Pattern 012: Terminal API Token Authentication
Route::prefix('sync')
    ->middleware([AuthenticateTerminalToken::class])
    ->group(function () {
        Route::get('/members', [SyncController::class, 'members']);
        Route::get('/categories', [SyncController::class, 'categories']);
        Route::get('/products', [SyncController::class, 'products']);
        Route::patch('/members/{memberId}/language', [SyncController::class, 'updateLanguage']);
        Route::post('/transactions', [SyncController::class, 'transactions']);
    });
