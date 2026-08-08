<?php

declare(strict_types=1);

use App\Shared\Controllers\HealthController;
use App\Modules\Auth\Controllers\AuthController;
use App\Modules\Members\Controllers\AdminController as MembersAdminController;
use App\Modules\Members\Controllers\MandateDocumentController;
use App\Modules\Members\Controllers\ExtractionController;
use App\Modules\Members\Controllers\SyncController as MembersSyncController;
use App\Modules\Products\Controllers\AdminController as ProductsAdminController;
use App\Modules\Products\Controllers\SyncController as ProductsSyncController;
use App\Modules\Transactions\Controllers\AdminController as TransactionsAdminController;
use App\Modules\Transactions\Controllers\SyncController as TransactionsSyncController;
use App\Modules\Settlements\Controllers\AdminController as SettlementsAdminController;
use App\Modules\Settlements\Controllers\SepaConfigController;
use App\Modules\AdminUsers\Controllers\AdminController as AdminUsersAdminController;
use App\Modules\AuditLog\Controllers\AdminController as AuditLogAdminController;
use App\Modules\Terminals\Controllers\AdminController as TerminalsAdminController;
use App\Modules\BankCodes\Controllers\AdminController as BankCodesAdminController;
use App\Modules\Dashboard\Controllers\AdminController as DashboardAdminController;
use App\Modules\Reports\Controllers\AdminController as ReportsAdminController;
use App\Modules\Auth\Middleware\AdminSessionAuth;
use App\Modules\Auth\Middleware\TerminalTokenAuth;
use App\Shared\Middleware\CsrfMiddleware;
use App\Shared\Middleware\RateLimitMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app): void {
    /** @var \App\ServiceFactory $factory */
    $factory = $app->getContainer();
    $terminalRateLimit = $factory->getTerminalRateLimitMiddleware();

    // Public health check
    $app->get('/api/health', [HealthController::class, 'check']);

    // Auth endpoints (login and mfa are public, rest require session)
    $app->post('/api/auth/login', [AuthController::class, 'login'])->add(RateLimitMiddleware::class);
    $app->post('/api/auth/mfa', [AuthController::class, 'mfa']);

    $app->group('/api/auth', function (RouteCollectorProxy $group) {
        $group->post('/logout', [AuthController::class, 'logout']);
        $group->get('/profile', [AuthController::class, 'profile']);
        $group->patch('/profile', [AuthController::class, 'updateProfile']);
        $group->patch('/change-password', [AuthController::class, 'changePassword']);
        // 2FA setup/confirm: accessible even with totp_setup_required (see AdminSessionAuth)
        $group->post('/2fa/setup', [AuthController::class, 'setup2fa']);
        $group->post('/2fa/confirm', [AuthController::class, 'confirm2fa']);
        // 2FA reset: requires full authenticated session
        $group->post('/2fa/reset', [AuthController::class, 'reset2fa']);
    })->add(CsrfMiddleware::class)->add(AdminSessionAuth::class);

    // Terminal sync endpoints (token auth)
    // Middleware order (reverse-add): $terminalRateLimit runs first (pre-check), then TerminalTokenAuth
    $app->group('/api/sync', function (RouteCollectorProxy $group) {
        $group->get('/members', [MembersSyncController::class, 'index']);
        $group->patch('/members/{memberId}/language', [MembersSyncController::class, 'updateLanguage']);
        $group->get('/categories', [ProductsSyncController::class, 'categories']);
        $group->get('/products', [ProductsSyncController::class, 'products']);
        $group->post('/transactions', [TransactionsSyncController::class, 'processBatch']);
    })->add(TerminalTokenAuth::class)->add($terminalRateLimit);

    $app->get('/api/terminal/transactions/{memberId}', [TransactionsSyncController::class, 'transactionHistory'])
        ->add(TerminalTokenAuth::class)
        ->add($terminalRateLimit);

    // Admin endpoints (session auth)
    $app->group('/api/admin', function (RouteCollectorProxy $group) {
        // Dashboard
        $group->get('/dashboard', [DashboardAdminController::class, 'show']);
        $group->get('/statistics/monthly', [DashboardAdminController::class, 'monthlyStats']);

        // Members
        // Static segments must stay above /members/{memberId} so they are not
        // swallowed by the placeholder route.
        $group->get('/members', [MembersAdminController::class, 'index']);
        $group->post('/members', [MembersAdminController::class, 'store']);
        $group->get('/members/credit-balances', [MembersAdminController::class, 'creditBalances']);
        $group->get('/members/{memberId}', [MembersAdminController::class, 'show']);
        $group->patch('/members/{memberId}', [MembersAdminController::class, 'update']);
        $group->delete('/members/{memberId}', [MembersAdminController::class, 'destroy']);
        $group->post('/members/{memberId}/export', [MembersAdminController::class, 'export']);
        $group->post('/members/{memberId}/anonymize', [MembersAdminController::class, 'anonymize']);
        $group->get('/sepa-mandate-template', [MembersAdminController::class, 'downloadMandateTemplate']);

        // Mandate documents
        $group->post('/members/{memberId}/mandate-document', [MandateDocumentController::class, 'upload']);
        $group->get('/members/{memberId}/mandate-document', [MandateDocumentController::class, 'download']);
        $group->delete('/members/{memberId}/mandate-document', [MandateDocumentController::class, 'delete']);
        $group->post('/mandate-document/extract', [ExtractionController::class, 'extract']);

        // Categories
        $group->get('/categories', [ProductsAdminController::class, 'listCategories']);
        $group->post('/categories', [ProductsAdminController::class, 'storeCategory']);
        $group->patch('/categories/{categoryId}', [ProductsAdminController::class, 'updateCategory']);
        $group->patch('/categories/{categoryId}/status', [ProductsAdminController::class, 'toggleCategoryStatus']);
        $group->delete('/categories/{categoryId}', [ProductsAdminController::class, 'deleteCategory']);

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
        // Storno: the transaction is the subject, not a parameter. No amount is
        // accepted — it is derived as the exact negation of the target (#169).
        // The three POST routes that used to sit here took a freely-typed
        // amount against a member; a correction *is* a storno (#158), and there
        // is no manual-purchase endpoint to replace them (UC-A21 rejected).
        $group->post('/transactions/{transactionId}/storno', [TransactionsAdminController::class, 'storno']);

        // Admin users
        $group->get('/admin-users', [AdminUsersAdminController::class, 'index']);
        $group->post('/admin-users', [AdminUsersAdminController::class, 'store']);
        $group->get('/admin-users/{id}', [AdminUsersAdminController::class, 'show']);
        $group->patch('/admin-users/{id}', [AdminUsersAdminController::class, 'update']);
        $group->delete('/admin-users/{id}', [AdminUsersAdminController::class, 'destroy']);
        $group->post('/admin-users/{id}/reactivate', [AdminUsersAdminController::class, 'reactivate']);
        $group->post('/admin-users/{id}/reset-password', [AdminUsersAdminController::class, 'resetPassword']);

        // Audit log
        $group->get('/audit-log', [AuditLogAdminController::class, 'index']);

        // Settlements
        // Static segments must stay above /settlements/{id} so they are not
        // swallowed by the placeholder route.
        $group->get('/settlements/execution-date-info', [SettlementsAdminController::class, 'executionDateInfo']);
        $group->get('/settlements/filter-preview', [SettlementsAdminController::class, 'filterPreview']);
        $group->post('/settlements/settle-filter', [SettlementsAdminController::class, 'settleFilter']);
        $group->post('/settlements/preview', [SettlementsAdminController::class, 'preview']);
        $group->post('/settlements', [SettlementsAdminController::class, 'store']);
        $group->get('/settlements', [SettlementsAdminController::class, 'index']);
        $group->get('/settlements/{id}', [SettlementsAdminController::class, 'show']);
        $group->delete('/settlements/{id}', [SettlementsAdminController::class, 'destroy']);
        $group->delete('/settlements/{id}/cancel', [SettlementsAdminController::class, 'cancel']);
    $group->post('/settlements/{id}/submit', [SettlementsAdminController::class, 'markSubmitted']);
        $group->get('/settlements/{id}/export/sepa-xml', [SettlementsAdminController::class, 'exportSepa']);
        $group->get('/settlements/{id}/export/csv', [SettlementsAdminController::class, 'exportCsv']);
        $group->get('/settlements/{id}/export-transactions', [SettlementsAdminController::class, 'exportTransactionsCsv']);

        // SEPA config
        $group->get('/sepa-config', [SepaConfigController::class, 'show']);
        $group->post('/sepa-config', [SepaConfigController::class, 'update']);
        $group->put('/sepa-config', [SepaConfigController::class, 'update']);
        $group->patch('/sepa-config', [SepaConfigController::class, 'update']);

        // Reports
        $group->get('/reports/member-ranking', [ReportsAdminController::class, 'memberRanking']);
        $group->get('/reports/terminal-activity', [ReportsAdminController::class, 'terminalActivity']);
        $group->get('/reports/{reportType}/export', [ReportsAdminController::class, 'exportReport']);
        $group->get('/reports/{reportType}', [ReportsAdminController::class, 'getReport']);

        // Bank lookup
        $group->get('/bank-lookup', [BankCodesAdminController::class, 'lookup']);

        // Terminals
        $group->get('/terminals', [TerminalsAdminController::class, 'index']);
        $group->post('/terminals', [TerminalsAdminController::class, 'store']);
        $group->get('/terminals/{id}', [TerminalsAdminController::class, 'show']);
        $group->patch('/terminals/{id}', [TerminalsAdminController::class, 'update']);
        $group->delete('/terminals/{id}', [TerminalsAdminController::class, 'destroy']);
        $group->post('/terminals/{id}/rotate-token', [TerminalsAdminController::class, 'rotateToken']);
        $group->post('/terminals/{id}/revoke', [TerminalsAdminController::class, 'revoke']);
    })->add(CsrfMiddleware::class)->add(AdminSessionAuth::class);
};
