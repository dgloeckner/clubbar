<?php

use App\Http\Modules\Members\Controllers\AdminController;
use Illuminate\Support\Facades\Route;

/**
 * Admin API Routes - Members Module
 *
 * Admin endpoints for member management, GDPR export, and anonymization.
 * These routes are included by routes/modules/members.php
 * which is included by routes/api.php
 *
 * Implements Pattern 009: Module Structure (ADR-0018)
 *
 * Endpoints:
 * - GET /api/admin/members - List members (paginated, filterable)
 * - POST /api/admin/members - Create member
 * - GET /api/admin/members/{id} - View member
 * - PATCH /api/admin/members/{id} - Update member
 * - DELETE /api/admin/members/{id} - Delete member
 * - POST /api/admin/members/{id}/export - GDPR export
 * - POST /api/admin/members/{id}/anonymize - GDPR anonymize
 *
 * Authentication: Pattern 013 (Admin Session Authentication)
 * - TODO: Add session auth middleware when admin authentication is implemented
 * - For now, admin routes are public (for testing purposes)
 */

// Admin API endpoints
// TODO: Add middleware(['auth.session', 'admin']) when Pattern 013 implemented
Route::prefix('admin')
    ->group(function () {
        // RESTful resource routes for CRUD operations
        Route::apiResource('members', AdminController::class, [
            'parameters' => ['members' => 'memberId'],
        ]);

        // GDPR special operations
        Route::post('/members/{memberId}/export', [AdminController::class, 'export']);
        Route::post('/members/{memberId}/anonymize', [AdminController::class, 'anonymize']);
    });
