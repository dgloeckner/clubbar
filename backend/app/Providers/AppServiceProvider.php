<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * Implements Pattern 008: Service Provider Bindings
     * Centralizes dependency injection configuration.
     */
    public function register(): void
    {
        // Register services as singletons
        // Pattern 008: Service Provider Bindings

        // =====================================================================
        // SHARED SERVICES & REPOSITORIES
        // =====================================================================

        // Audit Service - Cross-cutting concern for audit logging (Pattern 016)
        $this->app->singleton(
            \App\Shared\Services\AuditService::class,
            function ($app) {
                return new \App\Shared\Services\AuditService();
            }
        );

        // Health check service
        $this->app->singleton(
            \App\Services\HealthCheckService::class,
            function ($app) {
                return new \App\Services\HealthCheckService();
            }
        );

        // Sync service (members, categories, products, language updates)
        $this->app->singleton(
            \App\Services\SyncService::class,
            function ($app) {
                return new \App\Services\SyncService();
            }
        );


        // =====================================================================
        // MEMBERS MODULE (Pattern 009: Module Structure)
        // =====================================================================

        // Members Repository - Data access abstraction (Pattern 011)
        $this->app->singleton(
            \App\Http\Modules\Members\Repositories\MembersRepository::class,
            function ($app) {
                return new \App\Http\Modules\Members\Repositories\MembersRepository();
            }
        );

        // Members Service - Business logic layer (Pattern 010)
        $this->app->singleton(
            \App\Http\Modules\Members\Services\MembersService::class,
            function ($app) {
                return new \App\Http\Modules\Members\Services\MembersService(
                    $app->make(\App\Http\Modules\Members\Repositories\MembersRepository::class),
                    $app->make(\App\Shared\Services\AuditService::class)
                );
            }
        );

        // =====================================================================
        // PRODUCTS MODULE (Pattern 009: Module Structure)
        // =====================================================================

        // Products Repository - Data access abstraction (Pattern 011)
        $this->app->singleton(
            \App\Http\Modules\Products\Repositories\ProductsRepository::class,
            function ($app) {
                return new \App\Http\Modules\Products\Repositories\ProductsRepository();
            }
        );

        // Categories Repository - Data access abstraction (Pattern 011)
        $this->app->singleton(
            \App\Http\Modules\Products\Repositories\CategoriesRepository::class,
            function ($app) {
                return new \App\Http\Modules\Products\Repositories\CategoriesRepository();
            }
        );

        // Products Service - Business logic layer (Pattern 010)
        $this->app->singleton(
            \App\Http\Modules\Products\Services\ProductsService::class,
            function ($app) {
                return new \App\Http\Modules\Products\Services\ProductsService(
                    $app->make(\App\Http\Modules\Products\Repositories\ProductsRepository::class),
                    $app->make(\App\Http\Modules\Products\Repositories\CategoriesRepository::class),
                    $app->make(\App\Shared\Services\AuditService::class)
                );
            }
        );

        // Categories Service - Business logic layer (Pattern 010)
        $this->app->singleton(
            \App\Http\Modules\Products\Services\CategoriesService::class,
            function ($app) {
                return new \App\Http\Modules\Products\Services\CategoriesService(
                    $app->make(\App\Http\Modules\Products\Repositories\CategoriesRepository::class),
                    $app->make(\App\Http\Modules\Products\Repositories\ProductsRepository::class),
                    $app->make(\App\Shared\Services\AuditService::class)
                );
            }
        );

        // =====================================================================
        // AUDIT LOG MODULE (Pattern 009: Module Structure)
        // =====================================================================

        // Audit Log Repository - Data access abstraction (Pattern 011)
        $this->app->singleton(
            \App\Http\Modules\AuditLog\Repositories\AuditLogRepository::class,
            function ($app) {
                return new \App\Http\Modules\AuditLog\Repositories\AuditLogRepository();
            }
        );

        // Audit Log Service - Business logic layer (Pattern 010)
        $this->app->singleton(
            \App\Http\Modules\AuditLog\Services\AuditLogService::class,
            function ($app) {
                return new \App\Http\Modules\AuditLog\Services\AuditLogService(
                    $app->make(\App\Http\Modules\AuditLog\Repositories\AuditLogRepository::class)
                );
            }
        );

        // =====================================================================
        // TRANSACTIONS MODULE (Pattern 009: Module Structure)
        // =====================================================================

        // Transactions Repository - Data access abstraction (Pattern 011)
        $this->app->singleton(
            \App\Http\Modules\Transactions\Services\TransactionsRepository::class,
            function ($app) {
                return new \App\Http\Modules\Transactions\Services\TransactionsRepository();
            }
        );

        // Transactions Service - Business logic layer (Pattern 010)
        $this->app->singleton(
            \App\Http\Modules\Transactions\Services\TransactionsService::class,
            function ($app) {
                return new \App\Http\Modules\Transactions\Services\TransactionsService(
                    $app->make(\App\Http\Modules\Transactions\Services\TransactionsRepository::class)
                );
            }
        );

        // =====================================================================
        // ADMIN USERS MODULE (Pattern 009: Module Structure)
        // =====================================================================

        // Admin Users Repository - Data access abstraction (Pattern 011)
        $this->app->singleton(
            \App\Http\Modules\AdminUsers\Repositories\AdminUsersRepository::class,
            function ($app) {
                return new \App\Http\Modules\AdminUsers\Repositories\AdminUsersRepository();
            }
        );

        // Admin Users Service - Business logic layer (Pattern 010)
        $this->app->singleton(
            \App\Http\Modules\AdminUsers\Services\AdminUsersService::class,
            function ($app) {
                return new \App\Http\Modules\AdminUsers\Services\AdminUsersService(
                    $app->make(\App\Http\Modules\AdminUsers\Repositories\AdminUsersRepository::class),
                    $app->make(\App\Shared\Services\AuditService::class)
                );
            }
        );

        // =====================================================================
        // TERMINALS MODULE (Pattern 009: Module Structure)
        // =====================================================================

        // Terminals Repository - Data access abstraction (Pattern 011)
        $this->app->singleton(
            \App\Http\Modules\Terminals\Repositories\TerminalsRepository::class,
            function ($app) {
                return new \App\Http\Modules\Terminals\Repositories\TerminalsRepository();
            }
        );

        // Terminals Service - Business logic layer (Pattern 010)
        $this->app->singleton(
            \App\Http\Modules\Terminals\Services\TerminalsService::class,
            function ($app) {
                return new \App\Http\Modules\Terminals\Services\TerminalsService(
                    $app->make(\App\Http\Modules\Terminals\Repositories\TerminalsRepository::class),
                    $app->make(\App\Shared\Services\AuditService::class)
                );
            }
        );

        // =====================================================================
        // DASHBOARD MODULE (Pattern 009: Module Structure)
        // =====================================================================

        // Dashboard Service - Business logic layer (Pattern 010, read-only variant)
        $this->app->singleton(
            \App\Http\Modules\Dashboard\Services\DashboardService::class,
            function ($app) {
                return new \App\Http\Modules\Dashboard\Services\DashboardService();
            }
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Add terminal() macro to Request for accessing authenticated terminal
        // Pattern 012: Terminal API Token Authentication
        \Illuminate\Http\Request::macro('terminal', function () {
            return $this->attributes->get('terminal');
        });
    }
}
