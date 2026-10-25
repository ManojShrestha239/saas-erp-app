<?php

declare(strict_types=1);

use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Controllers\AuthController;
use Modules\Auth\Http\Middleware\EnsureTenantSession;

Route::middleware([
    'identify.tenant',
    EncryptCookies::class,
    AddQueuedCookiesToResponse::class,
    StartSession::class,
    EnsureTenantSession::class,
])->prefix('auth')->group(function (): void {
    Route::post('login', [AuthController::class, 'login'])->name('auth.login');
    Route::post('logout', [AuthController::class, 'logout'])
        ->middleware('auth:web')
        ->name('auth.logout');
});
