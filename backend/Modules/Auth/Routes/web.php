<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Auth\Http\Middleware\EnsureTenantSession;

Route::middleware([
    'identify.tenant',
    EnsureTenantSession::class,
])->group(function (): void {
    Route::middleware('guest:web')->group(function (): void {
        Route::get('login', fn (): Response => Inertia::render('auth/Login'))
            ->name('login');

        Route::get('forgot-password', fn (): Response => Inertia::render('auth/ForgotPassword'))
            ->name('password.request');

        Route::get('reset-password/{token}', fn (string $token): Response => Inertia::render('auth/ResetPassword', ['token' => $token]))
            ->name('password.reset');
    });

    Route::middleware('auth:web')->group(function (): void {
        Route::get('verify-email', fn (): Response => Inertia::render('auth/VerifyEmail'))
            ->name('verification.notice');

        Route::get('users', fn (): Response => Inertia::render('users/UsersIndex'))
            ->name('users.view');
    });
});
