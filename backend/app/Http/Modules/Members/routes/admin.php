<?php

use App\Http\Modules\Members\Controllers\AdminController;
use Illuminate\Support\Facades\Route;

/**
 * Admin API Routes - Members Module (Stub)
 *
 * Placeholder for admin endpoints for Members module.
 * Full implementation in Milestone 4.
 *
 * These routes are included by routes/modules/members.php
 * which is included by routes/api.php
 *
 * Future Endpoints:
 * - GET /api/admin/members - List members
 * - POST /api/admin/members - Create member
 * - GET /api/admin/members/{id} - View member
 * - PATCH /api/admin/members/{id} - Update member
 * - DELETE /api/admin/members/{id} - Delete member
 * - POST /api/admin/members/{id}/export - GDPR export
 * - POST /api/admin/members/{id}/anonymize - GDPR anonymize
 */

// TODO: Implement admin routes in Milestone 4
// Route::middleware(['auth', 'admin'])
//     ->prefix('admin')
//     ->group(function () {
//         Route::apiResource('members', AdminController::class);
//         Route::post('/members/{id}/export', [AdminController::class, 'export']);
//         Route::post('/members/{id}/anonymize', [AdminController::class, 'anonymize']);
//     });
