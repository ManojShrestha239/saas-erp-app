<?php

declare(strict_types=1);

use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Modules\SuperAdmin\Http\Controllers\SuperAdminAuthController;
use Modules\SuperAdmin\Http\Controllers\SuperAdminDashboardController;
use Modules\SuperAdmin\Http\Controllers\SuperAdminFeatureFlagController;
use Modules\SuperAdmin\Http\Controllers\SuperAdminPackageController;
use Modules\SuperAdmin\Http\Controllers\SuperAdminSubscriptionController;
use Modules\SuperAdmin\Http\Controllers\SuperAdminTenantController;
use Shared\Http\Middleware\EnsureCentralDomain;

Route::middleware([
    EnsureCentralDomain::class,
    EncryptCookies::class,
    AddQueuedCookiesToResponse::class,
    StartSession::class,
])->prefix('super-admin')->group(function (): void {
    // Public Super Admin Auth
    Route::post('login', [SuperAdminAuthController::class, 'login'])
        ->name('super-admin.login');

    // Authenticated Super Admin Routes
    Route::middleware('auth:super_admin')->group(function (): void {
        Route::post('logout', [SuperAdminAuthController::class, 'logout'])
            ->name('super-admin.logout');

        Route::get('me', [SuperAdminAuthController::class, 'me'])
            ->name('super-admin.me');

        // Dashboard Stats
        Route::get('dashboard/stats', [SuperAdminDashboardController::class, 'stats'])
            ->name('super-admin.dashboard.stats');

        // Tenants Management
        Route::get('tenants', [SuperAdminTenantController::class, 'index'])
            ->name('super-admin.tenants.index');
        Route::get('tenants/{id}', [SuperAdminTenantController::class, 'show'])
            ->name('super-admin.tenants.show');
        Route::post('tenants/{id}/suspend', [SuperAdminTenantController::class, 'suspend'])
            ->name('super-admin.tenants.suspend');
        Route::post('tenants/{id}/reactivate', [SuperAdminTenantController::class, 'reactivate'])
            ->name('super-admin.tenants.reactivate');
        Route::post('tenants/{id}/retry-provisioning', [SuperAdminTenantController::class, 'retryProvisioning'])
            ->name('super-admin.tenants.retry-provisioning');

        // Packages Management
        Route::get('packages', [SuperAdminPackageController::class, 'index'])
            ->name('super-admin.packages.index');
        Route::post('packages', [SuperAdminPackageController::class, 'store'])
            ->name('super-admin.packages.store');
        Route::get('packages/{id}', [SuperAdminPackageController::class, 'show'])
            ->name('super-admin.packages.show');
        Route::put('packages/{id}', [SuperAdminPackageController::class, 'update'])
            ->name('super-admin.packages.update');
        Route::delete('packages/{id}', [SuperAdminPackageController::class, 'destroy'])
            ->name('super-admin.packages.destroy');

        // Subscriptions Management
        Route::get('subscriptions', [SuperAdminSubscriptionController::class, 'index'])
            ->name('super-admin.subscriptions.index');
        Route::post('subscriptions', [SuperAdminSubscriptionController::class, 'store'])
            ->name('super-admin.subscriptions.store');
        Route::post('subscriptions/{id}/cancel', [SuperAdminSubscriptionController::class, 'cancel'])
            ->name('super-admin.subscriptions.cancel');

        // Feature Flags
        Route::get('feature-flags', [SuperAdminFeatureFlagController::class, 'index'])
            ->name('super-admin.feature-flags.index');
        Route::put('feature-flags/{key}/toggle', [SuperAdminFeatureFlagController::class, 'toggle'])
            ->name('super-admin.feature-flags.toggle');
    });
});
