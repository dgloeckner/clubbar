<?php

use App\Http\Modules\Products\Controllers\AdminController;
use Illuminate\Support\Facades\Route;

/**
 * Admin API Routes - Products Module
 *
 * Admin endpoints for product and category management.
 * These routes are included by routes/modules/products.php
 * which is included by routes/api.php
 *
 * Implements Pattern 009: Module Structure (ADR-0018)
 *
 * Endpoints:
 * - GET /api/admin/categories - List categories
 * - POST /api/admin/categories - Create category
 * - PATCH /api/admin/categories/{id} - Update category
 * - PATCH /api/admin/categories/{id}/status - Toggle status
 * - DELETE /api/admin/categories/{id} - Delete category
 * - POST /api/admin/categories/reorder - Reorder categories
 * - GET /api/admin/products - List products
 * - POST /api/admin/products - Create product
 * - PATCH /api/admin/products/{id} - Update product
 * - PATCH /api/admin/products/{id}/status - Toggle status (active/inactive)
 * - DELETE /api/admin/products/{id} - Delete product (hard delete, non-recoverable)
 *
 * Authentication: Pattern 013 (Admin Session Authentication)
 * - All routes require admin session authentication (auth.admin middleware)
 */

Route::prefix('admin')
    ->middleware([
        \App\Http\Middleware\EncryptCookies::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\Session\Middleware\StartSession::class,
        \App\Http\Middleware\AuthenticateAdminSession::class,
    ])
    ->group(function () {
        // Category endpoints
        Route::get('/categories', [AdminController::class, 'listCategories']);
        Route::post('/categories', [AdminController::class, 'storeCategory']);
        Route::patch('/categories/{categoryId}', [AdminController::class, 'updateCategory']);
        Route::patch('/categories/{categoryId}/status', [AdminController::class, 'toggleCategoryStatus']);
        Route::delete('/categories/{categoryId}', [AdminController::class, 'deleteCategory']);
        Route::post('/categories/reorder', [AdminController::class, 'reorderCategories']);

        // Product endpoints
        Route::get('/products', [AdminController::class, 'listProducts']);
        Route::post('/products', [AdminController::class, 'storeProduct']);
        Route::patch('/products/{productId}', [AdminController::class, 'updateProduct']);
        Route::patch('/products/{productId}/status', [AdminController::class, 'toggleProductStatus']);
        Route::delete('/products/{productId}', [AdminController::class, 'deleteProduct']);
    });
