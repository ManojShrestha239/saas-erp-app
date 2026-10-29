<?php

declare(strict_types=1);

use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Middleware\EnsureTenantSession;
use Modules\Payments\Http\Controllers\PaymentController;

Route::middleware([
    'identify.tenant',
    EncryptCookies::class,
    AddQueuedCookiesToResponse::class,
    StartSession::class,
    EnsureTenantSession::class,
    'auth:web',
])->group(function (): void {
    Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
    Route::post('payments', [PaymentController::class, 'store'])->name('payments.store');
    Route::get('payments/{id}', [PaymentController::class, 'show'])->name('payments.show');
    Route::post('payments/{id}/post', [PaymentController::class, 'post'])->name('payments.post');
    Route::post('payments/{id}/allocate', [PaymentController::class, 'allocate'])->name('payments.allocate');
    Route::get('payments/contacts/{contactId}/unallocated-credit', [PaymentController::class, 'unallocatedCredit'])->name('payments.unallocated-credit');
});
