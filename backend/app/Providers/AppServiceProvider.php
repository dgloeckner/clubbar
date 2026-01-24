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

        // Transaction service (batch transaction processing)
        $this->app->singleton(
            \App\Services\TransactionService::class,
            function ($app) {
                return new \App\Services\TransactionService();
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
                    $app->make(\App\Http\Modules\Members\Repositories\MembersRepository::class)
                );
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
