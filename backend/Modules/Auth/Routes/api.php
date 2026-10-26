<?php

declare(strict_types=1);

use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Controllers\AuthController;
use Modules\Auth\Http\Controllers\EmailVerificationController;
use Modules\Auth\Http\Controllers\PasswordResetController;
use Modules\Auth\Http\Controllers\TenantUserController;
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

    Route::post('password/email', [PasswordResetController::class, 'sendResetLinkEmail'])
        ->name('auth.password.email');
    Route::post('password/reset', [PasswordResetController::class, 'resetPassword'])
        ->name('auth.password.reset');

    Route::post('email/verify', [EmailVerificationController::class, 'verify'])
        ->name('auth.email.verify');
    Route::post('email/resend', [EmailVerificationController::class, 'resend'])
        ->name('auth.email.resend');
});

Route::middleware([
    'identify.tenant',
    EncryptCookies::class,
    AddQueuedCookiesToResponse::class,
    StartSession::class,
    EnsureTenantSession::class,
    'auth:web',
])->prefix('users')->group(function (): void {
    Route::get('', [TenantUserController::class, 'index'])->name('users.index');
    Route::post('invite', [TenantUserController::class, 'invite'])->name('users.invite');
    Route::patch('{id}/role', [TenantUserController::class, 'updateRole'])->name('users.update-role');
    Route::patch('{id}/status', [TenantUserController::class, 'updateStatus'])->name('users.update-status');
});
