<?php

declare(strict_types=1);

use App\Shared\Controllers\HealthController;
use App\Shared\Controllers\SecurityCheckController;
use App\Modules\Security\Controllers\EncryptionKeysController;
use App\Modules\Auth\Controllers\AuthController;
use App\Modules\Members\Controllers\AdminController as MembersAdminController;
use App\Modules\Members\Controllers\SyncController as MembersSyncController;
use App\Modules\Products\Controllers\AdminController as ProductsAdminController;
use App\Modules\Products\Controllers\SyncController as ProductsSyncController;
use App\Modules\Transactions\Controllers\AdminController as TransactionsAdminController;
use App\Modules\Transactions\Controllers\SyncController as TransactionsSyncController;
use App\Modules\Settlements\Controllers\AdminController as SettlementsAdminController;
use App\Modules\Settlements\Controllers\SepaConfigController;
use App\Modules\Instance\Controllers\InstanceConfigController;
use App\Modules\Notifications\Controllers\CronController;
use App\Modules\Notifications\Controllers\MailConfigController;
use App\Modules\Notifications\Controllers\NotificationsController;
use App\Modules\Notifications\Controllers\SchedulerController;
use App\Modules\AdminUsers\Controllers\AdminController as AdminUsersAdminController;
use App\Modules\AuditLog\Controllers\AdminController as AuditLogAdminController;
use App\Modules\Terminals\Controllers\AdminController as TerminalsAdminController;
use App\Modules\Terminals\Controllers\PairingController;
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
    $stepUpRateLimit = $factory->getStepUpRateLimitMiddleware();

    // Public health check
    $app->get('/api/health', [HealthController::class, 'check']);

    // Public instance branding read — needed before login (login page) and by
    // the Terminal, which has no admin session (ADR-0034).
    $app->get('/api/instance-config', [InstanceConfigController::class, 'show']);

    // The URL fallback for the mail drain (ADR-0038 rule 3, #403).
    //
    // Public by necessity — a panel's URL cron carries no session and no CSRF
    // token — and authorised instead by a dedicated secret in config.php, which
    // the controller checks in constant time. Without that secret configured
    // the route answers 404: an installation on a CLI cron gains no second
    // entrance.
    //
    // GET and POST both, because panels differ on which they can schedule, and
    // for the same reason it is deliberately outside the CSRF middleware: there
    // is no browser and no session cookie for a CSRF token to protect.
    $app->map(['GET', 'POST'], '/api/cron/drain', [CronController::class, 'drain']);

    // Auth endpoints (login and mfa are public, rest require session).
    // Both password and second factor are rate-limited on IP and account (#78,
    // ruling #145) — an unlimited MFA endpoint made the second factor guessable.
    $app->post('/api/auth/login', [AuthController::class, 'login'])->add(RateLimitMiddleware::class);
    $app->post('/api/auth/mfa', [AuthController::class, 'mfa'])->add($factory->getMfaRateLimitMiddleware());

    $app->group('/api/auth', function (RouteCollectorProxy $group) use ($stepUpRateLimit) {
        $group->post('/logout', [AuthController::class, 'logout']);
        $group->get('/profile', [AuthController::class, 'profile']);
        // Both carry a step-up credential when they move a credential: always
        // for the password, and for the profile only when the email — the login
        // identifier — actually changes, so a locale switch needs no password.
        //
        // The limiter is on the whole profile route rather than only the
        // email path, because a limiter the controller reaches after deciding
        // to step up is a limiter an attacker can call unboundedly. The cost is
        // that five failed step-ups also lock the harmless half of this
        // endpoint for fifteen minutes — a locale switch answering 429 is a
        // safe failure, and the alternative is an unmetered guessing oracle.
        $group->patch('/profile', [AuthController::class, 'updateProfile'])->add($stepUpRateLimit);
        $group->patch('/change-password', [AuthController::class, 'changePassword'])->add($stepUpRateLimit);
        // 2FA setup/confirm: accessible even with totp_setup_required (see AdminSessionAuth)
        $group->post('/2fa/setup', [AuthController::class, 'setup2fa']);
        $group->post('/2fa/confirm', [AuthController::class, 'confirm2fa']);
        // 2FA reset: requires full authenticated session, plus a fresh
        // step-up credential (#337) — rate-limited on the caller's own
        // account so the credential can't be brute-forced.
        $group->post('/2fa/reset', [AuthController::class, 'reset2fa'])->add($stepUpRateLimit);
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

    // ADR-0035: staff at the bar confirming a pairing mismatch is safe to
    // clear. Terminal-authenticated, not admin-authenticated — the terminal
    // itself is the one whose local state changes.
    $app->post('/api/terminal/pairing/ack', [PairingController::class, 'acknowledge'])
        ->add(TerminalTokenAuth::class)
        ->add($terminalRateLimit);

    // Admin endpoints (session auth)
    $app->group('/api/admin', function (RouteCollectorProxy $group) use ($stepUpRateLimit) {
        // Security self-check. Admin-only because the report names this
        // installation's weak points and the paths its member documents live
        // in (#247, ADR-0031 decision 3).
        $group->get('/security-check', [SecurityCheckController::class, 'show']);

        // Dashboard
        $group->get('/dashboard', [DashboardAdminController::class, 'show']);
        $group->get('/statistics/monthly', [DashboardAdminController::class, 'monthlyStats']);

        // Members
        // Static segments must stay above /members/{memberId} so they are not
        // swallowed by the placeholder route.
        $group->get('/members', [MembersAdminController::class, 'index']);
        $group->post('/members', [MembersAdminController::class, 'store']);
        $group->get('/members/credit-balances', [MembersAdminController::class, 'creditBalances']);
        $group->get('/members/collection-holds', [MembersAdminController::class, 'collectionHolds']);
        $group->get('/members/mandate-missing', [MembersAdminController::class, 'mandateMissing']);
        $group->get('/members/{memberId}', [MembersAdminController::class, 'show']);
        $group->patch('/members/{memberId}', [MembersAdminController::class, 'update']);
        $group->delete('/members/{memberId}', [MembersAdminController::class, 'destroy']);
        $group->post('/members/{memberId}/export', [MembersAdminController::class, 'export']);
        $group->post('/members/{memberId}/anonymize', [MembersAdminController::class, 'anonymize']);
        // Clearing is an admin decision, not a member edit — it lets the next
        // collection run reach this member again (ruling #148 §5).
        $group->post('/members/{memberId}/collection-hold/clear', [MembersAdminController::class, 'clearCollectionHold']);

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

        // The acknowledgement half of #622. Keyed on the *audit entry's* id,
        // because that entry is the violation — there is no separate violations
        // table, deliberately (migration 051).
        $group->get('/jugendschutz-violations', [TransactionsAdminController::class, 'getJugendschutzViolations']);
        $group->post('/jugendschutz-violations/{id}/acknowledge', [TransactionsAdminController::class, 'acknowledgeJugendschutzViolation']);

        // Admin users
        $group->get('/admin-users', [AdminUsersAdminController::class, 'index']);
        $group->post('/admin-users', [AdminUsersAdminController::class, 'store'])->add($stepUpRateLimit);
        $group->get('/admin-users/{id}', [AdminUsersAdminController::class, 'show']);
        // Carries a step-up when the role set actually changes (ADR-0044 rule
        // 2), so it shares the step-up rate-limit dimension for the same
        // reason `POST /admin-users` does (#500, #511): a limiter the
        // controller only reaches after deciding to demand a credential is a
        // limiter an attacker can call unboundedly.
        $group->patch('/admin-users/{id}', [AdminUsersAdminController::class, 'update'])->add($stepUpRateLimit);
        $group->delete('/admin-users/{id}', [AdminUsersAdminController::class, 'destroy']);
        $group->post('/admin-users/{id}/reactivate', [AdminUsersAdminController::class, 'reactivate']);
        // Cross-account password reset requires a step-up credential (#337),
        // same rate-limit dimension as the 2FA reset above.
        $group->post('/admin-users/{id}/reset-password', [AdminUsersAdminController::class, 'resetPassword'])->add($stepUpRateLimit);

        // Audit log
        $group->get('/audit-log', [AuditLogAdminController::class, 'index']);

        // IBAN encryption keys (ADR-0036). Mutations carry a step-up
        // credential in the body and share the step-up rate-limit dimension.
        $group->get('/encryption-keys', [EncryptionKeysController::class, 'index']);
        $group->post('/encryption-keys', [EncryptionKeysController::class, 'store'])->add($stepUpRateLimit);
        $group->post('/encryption-keys/{id}/activate', [EncryptionKeysController::class, 'activate'])->add($stepUpRateLimit);
        $group->post('/encryption-keys/{id}/revoke', [EncryptionKeysController::class, 'revoke'])->add($stepUpRateLimit);
        // Rotation (#394): one request per batch of 100 rows, each carrying the
        // private half of the key being rotated away from. The wizard calls
        // rotate-batch until nothing is left, then complete-rotation retires it.
        $group->post('/encryption-keys/{id}/rotate-batch', [EncryptionKeysController::class, 'rotateBatch'])->add($stepUpRateLimit);
        $group->post('/encryption-keys/{id}/complete-rotation', [EncryptionKeysController::class, 'completeRotation'])->add($stepUpRateLimit);

        // Settlements
        // Static segments must stay above /settlements/{id} so they are not
        // swallowed by the placeholder route.
        $group->get('/settlements/execution-date-info', [SettlementsAdminController::class, 'executionDateInfo']);
        $group->get('/settlements/filter-preview', [SettlementsAdminController::class, 'filterPreview']);
        $group->post('/settlements/settle-filter', [SettlementsAdminController::class, 'settleFilter']);
        $group->post('/settlements/preview', [SettlementsAdminController::class, 'preview']);
        // Which collections a bank reference points at (#433, ADR-0032 §8) —
        // the lookup a treasurer holding a return booking starts from, before
        // they know which run it came out of.
        $group->get('/settlements/reversal-candidates', [SettlementsAdminController::class, 'reversalCandidates']);
        $group->post('/settlements', [SettlementsAdminController::class, 'store']);
        $group->get('/settlements', [SettlementsAdminController::class, 'index']);
        $group->get('/settlements/{id}', [SettlementsAdminController::class, 'show']);
        // One cancellation route, not two. The bodyless `DELETE
        // /settlements/{id}` alias was undocumented, uncalled, and split the
        // test coverage of the cancellation gate across two entry points
        // (#120).
        $group->delete('/settlements/{id}/cancel', [SettlementsAdminController::class, 'cancel']);
        $group->post('/settlements/{id}/submit', [SettlementsAdminController::class, 'markSubmitted']);
        // The other end of #81: once submitted, a settlement is reversed, not
        // cancelled. Per member, or every member for a whole-settlement undo.
        $group->post('/settlements/{id}/reverse', [SettlementsAdminController::class, 'reverse']);
        // POST, not GET: the club's private key travels in the body so the
        // sealed IBANs can be opened for this one request (ADR-0036, #393).
        // Step-up-rate-limited like every other credential-bearing endpoint.
        $group->post('/settlements/{id}/export/sepa-xml', [SettlementsAdminController::class, 'exportSepa'])->add($stepUpRateLimit);
        $group->get('/settlements/{id}/export/csv', [SettlementsAdminController::class, 'exportCsv']);
        $group->get('/settlements/{id}/export-transactions', [SettlementsAdminController::class, 'exportTransactionsCsv']);

        // SEPA config
        $group->get('/sepa-config', [SepaConfigController::class, 'show']);
        $group->post('/sepa-config', [SepaConfigController::class, 'update']);
        $group->put('/sepa-config', [SepaConfigController::class, 'update']);
        $group->patch('/sepa-config', [SepaConfigController::class, 'update']);

        // Instance branding
        $group->patch('/instance-config', [InstanceConfigController::class, 'update']);

        // Mail settings (ADR-0038). The SMTP DSN is not here on purpose — it is
        // a secret in config.php; these are the club-editable fields only.
        $group->get('/mail-config', [MailConfigController::class, 'show']);
        $group->patch('/mail-config', [MailConfigController::class, 'update']);
        // The one diagnostic that sends from a request. It goes to the
        // requesting admin's own address and nowhere else — see TestMailService
        // for why that does not make it a second sending path.
        $group->post('/mail-config/test-mail', [MailConfigController::class, 'sendTestMail']);
        // Mints a credential — the URL-trigger secret CronController checks —
        // so it carries a step-up in the body and shares the step-up
        // rate-limit dimension, same as terminal tokens (#395, #473).
        $group->post('/mail-config/cron-secret/rotate', [MailConfigController::class, 'rotateCronSecret'])->add($stepUpRateLimit);

        // The mail queue (#407). Read, and exactly one state change: a failed
        // message can go back to `pending`. Nothing here drains, and nothing
        // here reports progress for a client to poll (ADR-0038 rule 4).
        $group->get('/notifications', [NotificationsController::class, 'index']);
        $group->post('/notifications/{id}/retry', [NotificationsController::class, 'retry']);

        // Whether a drain run has ever been observed, and what to schedule if
        // not (#405). Read-only: it reports the heartbeat, it never writes one.
        $group->get('/scheduler', [SchedulerController::class, 'show']);

        // Reports
        $group->get('/reports/terminal-activity', [ReportsAdminController::class, 'terminalActivity']);
        $group->get('/reports/{reportType}', [ReportsAdminController::class, 'getReport']);

        // Bank lookup
        $group->get('/bank-lookup', [BankCodesAdminController::class, 'lookup']);

        // Terminals. The two endpoints that mint a credential carry a step-up
        // credential in the body and share the step-up rate-limit dimension
        // (#395); revocation deliberately does not, so withdrawing access is
        // never harder than granting it.
        $group->get('/terminals', [TerminalsAdminController::class, 'index']);
        $group->post('/terminals', [TerminalsAdminController::class, 'store'])->add($stepUpRateLimit);
        $group->get('/terminals/{id}', [TerminalsAdminController::class, 'show']);
        $group->patch('/terminals/{id}', [TerminalsAdminController::class, 'update']);
        $group->delete('/terminals/{id}', [TerminalsAdminController::class, 'destroy']);
        $group->post('/terminals/{id}/rotate-token', [TerminalsAdminController::class, 'rotateToken'])->add($stepUpRateLimit);
        $group->post('/terminals/{id}/revoke', [TerminalsAdminController::class, 'revoke']);
        // ADR-0041: clears the alert, never the credential.
        $group->get('/terminals/{id}/anomalies', [TerminalsAdminController::class, 'listAnomalies']);
        $group->post('/terminals/{id}/anomalies/{anomalyId}/acknowledge', [TerminalsAdminController::class, 'acknowledgeAnomaly']);
    })->add(CsrfMiddleware::class)->add(AdminSessionAuth::class);
};
