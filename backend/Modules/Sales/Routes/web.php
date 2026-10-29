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
    Route::get('sales', fn () => redirect('/quotations'))
        ->name('sales.dashboard');

    Route::get('quotations', fn (): Response => Inertia::render('sales/QuotationsIndex'))
        ->name('sales.quotations.page');

    Route::get('invoices', fn (): Response => Inertia::render('sales/InvoicesIndex'))
        ->name('sales.invoices.page');
});
