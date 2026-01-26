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

    // Transactions module routes (Terminal Sync + Admin Management)
    require base_path('routes/modules/transactions.php');

    // Admin Users module routes (Admin user CRUD and password management)
    require base_path('routes/modules/admin-users.php');

    // Settlements module routes (SEPA + Manual settlements with export)
    require base_path('routes/modules/settlements.php');
});
