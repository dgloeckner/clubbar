<?php

declare(strict_types=1);

use App\Controller\AdminUsersController;
use App\Controller\AuditLogController;
use App\Controller\AuthController;
use App\Controller\DashboardController;
use App\Controller\HealthController;
use App\Controller\MembersAdminController;
use App\Controller\MembersSyncController;
use App\Controller\ProductsAdminController;
use App\Controller\ProductsSyncController;
use App\Controller\SepaConfigController;
use App\Controller\SettlementsController;
use App\Controller\TerminalsController;
use App\Controller\TransactionsAdminController;
use App\Controller\TransactionsSyncController;
use App\Middleware\AdminSessionAuth;
use App\Middleware\TerminalTokenAuth;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app): void {
    // Public health check
    $app->get('/api/health', [HealthController::class, 'check']);

    // Auth endpoints (login is public, rest require session)
    $app->post('/api/auth/login', [AuthController::class, 'login']);

    $app->group('/api/auth', function (RouteCollectorProxy $group) {
        $group->post('/logout', [AuthController::class, 'logout']);
        $group->get('/profile', [AuthController::class, 'profile']);
        $group->patch('/change-password', [AuthController::class, 'changePassword']);
    })->add(AdminSessionAuth::class);

    // Terminal sync endpoints (token auth)
    $app->group('/api/sync', function (RouteCollectorProxy $group) {
        $group->get('/members', [MembersSyncController::class, 'index']);
        $group->patch('/members/{memberId}/language', [MembersSyncController::class, 'updateLanguage']);
        $group->get('/categories', [ProductsSyncController::class, 'categories']);
        $group->get('/products', [ProductsSyncController::class, 'products']);
        $group->post('/transactions', [TransactionsSyncController::class, 'processBatch']);
    })->add(TerminalTokenAuth::class);

    $app->get('/api/terminal/transactions/{memberId}', [TransactionsSyncController::class, 'transactionHistory'])
        ->add(TerminalTokenAuth::class);

    // Admin endpoints (session auth)
    $app->group('/api/admin', function (RouteCollectorProxy $group) {
        // Dashboard
        $group->get('/dashboard', [DashboardController::class, 'show']);

        // Members
        $group->get('/members', [MembersAdminController::class, 'index']);
        $group->post('/members', [MembersAdminController::class, 'store']);
        $group->get('/members/{memberId}', [MembersAdminController::class, 'show']);
        $group->patch('/members/{memberId}', [MembersAdminController::class, 'update']);
        $group->delete('/members/{memberId}', [MembersAdminController::class, 'destroy']);
        $group->get('/members/{memberId}/export', [MembersAdminController::class, 'export']);
        $group->post('/members/{memberId}/anonymize', [MembersAdminController::class, 'anonymize']);

        // Categories
        $group->get('/categories', [ProductsAdminController::class, 'listCategories']);
        $group->post('/categories', [ProductsAdminController::class, 'storeCategory']);
        $group->patch('/categories/{categoryId}', [ProductsAdminController::class, 'updateCategory']);
        $group->patch('/categories/{categoryId}/status', [ProductsAdminController::class, 'toggleCategoryStatus']);
        $group->delete('/categories/{categoryId}', [ProductsAdminController::class, 'deleteCategory']);
        $group->post('/categories/reorder', [ProductsAdminController::class, 'reorderCategories']);

        // Products
        $group->get('/products', [ProductsAdminController::class, 'listProducts']);
        $group->post('/products', [ProductsAdminController::class, 'storeProduct']);
        $group->patch('/products/{productId}', [ProductsAdminController::class, 'updateProduct']);
        $group->patch('/products/{productId}/status', [ProductsAdminController::class, 'toggleProductStatus']);
        $group->delete('/products/{productId}', [ProductsAdminController::class, 'deleteProduct']);

        // Transactions
        $group->get('/transactions', [TransactionsAdminController::class, 'getTransactions']);
        $group->get('/transactions/export', [TransactionsAdminController::class, 'exportTransactions']);
        $group->get('/members/{memberId}/transactions', [TransactionsAdminController::class, 'getTransactionHistory']);
        $group->post('/members/{memberId}/transactions/correction', [TransactionsAdminController::class, 'recordCorrection']);

        // Admin users
        $group->get('/admin-users', [AdminUsersController::class, 'index']);
        $group->post('/admin-users', [AdminUsersController::class, 'store']);
        $group->get('/admin-users/{id}', [AdminUsersController::class, 'show']);
        $group->patch('/admin-users/{id}', [AdminUsersController::class, 'update']);
        $group->delete('/admin-users/{id}', [AdminUsersController::class, 'destroy']);
        $group->post('/admin-users/{id}/reactivate', [AdminUsersController::class, 'reactivate']);
        $group->post('/admin-users/{id}/reset-password', [AdminUsersController::class, 'resetPassword']);

        // Audit log
        $group->get('/audit-log', [AuditLogController::class, 'index']);

        // Settlements
        $group->post('/settlements/preview', [SettlementsController::class, 'preview']);
        $group->post('/settlements', [SettlementsController::class, 'store']);
        $group->get('/settlements', [SettlementsController::class, 'index']);
        $group->get('/settlements/{id}', [SettlementsController::class, 'show']);
        $group->delete('/settlements/{id}', [SettlementsController::class, 'destroy']);
        $group->get('/settlements/{id}/export-sepa', [SettlementsController::class, 'exportSepa']);
        $group->get('/settlements/{id}/export-csv', [SettlementsController::class, 'exportCsv']);
        $group->get('/settlements/{id}/export-transactions', [SettlementsController::class, 'exportTransactionsCsv']);

        // SEPA config
        $group->get('/sepa-config', [SepaConfigController::class, 'show']);
        $group->put('/sepa-config', [SepaConfigController::class, 'update']);

        // Terminals
        $group->get('/terminals', [TerminalsController::class, 'index']);
        $group->post('/terminals', [TerminalsController::class, 'store']);
        $group->get('/terminals/{id}', [TerminalsController::class, 'show']);
        $group->patch('/terminals/{id}', [TerminalsController::class, 'update']);
        $group->delete('/terminals/{id}', [TerminalsController::class, 'destroy']);
        $group->post('/terminals/{id}/rotate-token', [TerminalsController::class, 'rotateToken']);
        $group->post('/terminals/{id}/revoke', [TerminalsController::class, 'revoke']);
    })->add(AdminSessionAuth::class);
};
