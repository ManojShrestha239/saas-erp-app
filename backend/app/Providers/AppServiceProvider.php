<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Shared\Tenancy\TenantContext;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
        $this->enforceProductionSafeguards();
    }

    protected function enforceProductionSafeguards(): void
    {
        if ($this->app->isProduction()) {
            config(['telescope.enabled' => false]);
            config(['debugbar.enabled' => false]);
        }
    }

    protected function configureRateLimiting(): void
    {
        // General API rate limiter: 120 per minute per tenant or IP
        RateLimiter::for('api', function (Request $request): Limit {
            $tenant = TenantContext::get();
            $key = $tenant !== null ? "tenant:{$tenant->id}" : ($request->ip() ?: 'unknown');

            return Limit::perMinute(120)->by($key);
        });

        // Strict authentication rate limiter: 5 per minute per IP
        RateLimiter::for('auth', function (Request $request): Limit {
            $key = $request->ip() ?: 'unknown';

            return Limit::perMinute(5)->by($key);
        });

        // Report downloads & exports: 60 per minute per IP
        RateLimiter::for('downloads', function (Request $request): Limit {
            $key = $request->ip() ?: 'unknown';

            return Limit::perMinute(60)->by($key);
        });
    }
}
