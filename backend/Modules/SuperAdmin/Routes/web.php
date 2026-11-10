<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;
use Shared\Http\Middleware\EnsureCentralDomain;

Route::middleware([
    EnsureCentralDomain::class,
    'web',
])->prefix('admin')->group(function (): void {
    // Guest Login
    Route::get('login', fn (): Response => Inertia::render('super-admin/SuperAdminLogin'))
        ->name('super-admin.web.login');

    // Authenticated Super Admin Portal
    Route::middleware('auth:super_admin')->group(function (): void {
        Route::get('/', fn () => redirect()->route('super-admin.web.dashboard'));

        Route::get('dashboard', fn (): Response => Inertia::render('super-admin/SuperAdminDashboard', [
            'initialTab' => 'overview',
        ]))->name('super-admin.web.dashboard');

        Route::get('tenants', fn (): Response => Inertia::render('super-admin/SuperAdminDashboard', [
            'initialTab' => 'tenants',
        ]))->name('super-admin.web.tenants');

        Route::get('packages', fn (): Response => Inertia::render('super-admin/SuperAdminDashboard', [
            'initialTab' => 'packages',
        ]))->name('super-admin.web.packages');

        Route::get('subscriptions', fn (): Response => Inertia::render('super-admin/SuperAdminDashboard', [
            'initialTab' => 'subscriptions',
        ]))->name('super-admin.web.subscriptions');

        Route::get('feature-flags', fn (): Response => Inertia::render('super-admin/SuperAdminDashboard', [
            'initialTab' => 'feature-flags',
        ]))->name('super-admin.web.feature-flags');

        Route::get('audit-logs', fn (): Response => Inertia::render('super-admin/SuperAdminDashboard', [
            'initialTab' => 'audit-logs',
        ]))->name('super-admin.web.audit-logs');
    });
});
