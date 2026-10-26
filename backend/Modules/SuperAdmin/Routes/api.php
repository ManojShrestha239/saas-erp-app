<?php

declare(strict_types=1);

use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Modules\SuperAdmin\Http\Controllers\SuperAdminAuthController;
use Shared\Http\Middleware\EnsureCentralDomain;

Route::middleware([
    EnsureCentralDomain::class,
    EncryptCookies::class,
    AddQueuedCookiesToResponse::class,
    StartSession::class,
])->prefix('super-admin')->group(function (): void {
    Route::post('login', [SuperAdminAuthController::class, 'login'])
        ->name('super-admin.login');

    Route::post('logout', [SuperAdminAuthController::class, 'logout'])
        ->middleware('auth:super_admin')
        ->name('super-admin.logout');

    Route::get('me', [SuperAdminAuthController::class, 'me'])
        ->middleware('auth:super_admin')
        ->name('super-admin.me');
});
