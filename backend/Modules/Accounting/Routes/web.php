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
    Route::get('accounting', fn (): Response => Inertia::render('accounting/AccountingIndex'))
        ->name('accounting.index');
});
