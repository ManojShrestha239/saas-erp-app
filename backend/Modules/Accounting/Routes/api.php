<?php

declare(strict_types=1);

use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Modules\Accounting\Http\Controllers\AccountController;
use Modules\Auth\Http\Middleware\EnsureTenantSession;

Route::middleware([
    'identify.tenant',
    EncryptCookies::class,
    AddQueuedCookiesToResponse::class,
    StartSession::class,
    EnsureTenantSession::class,
    'auth:web',
])->group(function (): void {
    Route::get('accounting/accounts', [AccountController::class, 'index'])->name('accounting.accounts.index');
    Route::post('accounting/accounts', [AccountController::class, 'store'])->name('accounting.accounts.store');
    Route::get('accounting/accounts/{id}', [AccountController::class, 'show'])->name('accounting.accounts.show');
    Route::put('accounting/accounts/{id}', [AccountController::class, 'update'])->name('accounting.accounts.update');
    Route::delete('accounting/accounts/{id}', [AccountController::class, 'destroy'])->name('accounting.accounts.destroy');
});
