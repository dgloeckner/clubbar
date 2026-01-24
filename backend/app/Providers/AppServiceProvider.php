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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
