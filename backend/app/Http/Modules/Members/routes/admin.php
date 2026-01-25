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
 * - POST /api/admin/members/{id}/transactions/correct - Record correction (UC-A21)
 * - GET /api/admin/transactions/export - Export transactions as CSV (UC-A22)
 *
 * Authentication: Pattern 013 (Admin Session Authentication)
 * - All routes require admin session authentication (auth.admin middleware)
 * - Session is established via POST /api/auth/login endpoint
 */

// Admin API endpoints
Route::prefix('admin')
    ->middleware([
        \App\Http\Middleware\EncryptCookies::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\Session\Middleware\StartSession::class,
        \App\Http\Middleware\AuthenticateAdminSession::class,
    ])
    ->group(function () {
        // RESTful resource routes for CRUD operations
        Route::apiResource('members', AdminController::class, [
            'parameters' => ['members' => 'memberId'],
        ]);

        // GDPR special operations
        Route::post('/members/{memberId}/export', [AdminController::class, 'export']);
        Route::post('/members/{memberId}/anonymize', [AdminController::class, 'anonymize']);

        // Transaction operations
        Route::post('/members/{memberId}/transactions/correct', [AdminController::class, 'recordCorrection']);
        Route::get('/transactions/export', [AdminController::class, 'exportTransactions']);
    });
