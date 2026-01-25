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

// Health check (no auth required - public)
Route::get('/health', [HealthController::class, 'health']);

// Test POST route (temporary - for debugging)
Route::post('/test', function () {
    return response()->json(['message' => 'POST works!'], 200);
});

// =============================================================================
// MODULE ROUTES (ADR-0018: Modular Architecture)
// =============================================================================
// Routes are organized by module. Each module owns its endpoints.
// Pattern 009: Module Structure

// Module routes grouped with 'api' middleware (sessions, CORS, rate limiting)
Route::middleware('api')->group(function () {
    // Members module routes (Terminal API: /api/sync/members, Admin API: /api/admin/members)
    require base_path('routes/modules/members.php');

    // Audit Log module routes (Admin API: /api/admin/audit-log)
    require base_path('routes/modules/audit-log.php');

    // Products module routes (Categories and Products: Terminal Sync + Admin CRUD)
    require base_path('routes/modules/products.php');

    // Temporary: Keep transaction endpoints in flat structure
    // (Transactions will be migrated to Products module in future if needed)
    Route::prefix('sync')
        ->middleware([AuthenticateTerminalToken::class])
        ->group(function () {
            Route::post('/transactions', [SyncController::class, 'transactions']);
        });

    // Terminal API: Transaction History (Milestone 2.A - ADR-0024)
    Route::prefix('terminal')
        ->middleware([AuthenticateTerminalToken::class])
        ->group(function () {
            Route::get('/transactions/{memberId}', [SyncController::class, 'transactionHistory']);
        });
});
