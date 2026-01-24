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
        // Register HealthCheckService as singleton
        // Pattern 008: Service Provider Bindings
        $this->app->singleton(
            \App\Services\HealthCheckService::class,
            function ($app) {
                return new \App\Services\HealthCheckService();
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
