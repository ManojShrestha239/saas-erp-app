<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Auth\Http\Middleware\EnsureTenantSession;

Route::middleware([
    'identify.tenant',
    EnsureTenantSession::class,
    'auth:web',
])->group(function (): void {
    Route::get('customer-receipts', fn (): Response => Inertia::render('payments/CustomerReceiptsIndex'))
        ->name('payments.customer-receipts.page');

    Route::get('supplier-payments', fn (): Response => Inertia::render('payments/SupplierPaymentsIndex'))
        ->name('payments.supplier-payments.page');
});
