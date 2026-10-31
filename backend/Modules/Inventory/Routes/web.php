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
    Route::get('inventory', fn (): Response => Inertia::render('inventory/InventoryIndex'))
        ->name('inventory.index');

    Route::get('products', fn () => redirect('/inventory'))
        ->name('inventory.products.redirect');
});
