<?php

declare(strict_types=1);

use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Middleware\EnsureTenantSession;
use Modules\Inventory\Http\Controllers\CategoryController;
use Modules\Inventory\Http\Controllers\ProductController;
use Modules\Inventory\Http\Controllers\UnitOfMeasureController;

Route::middleware([
    'identify.tenant',
    EncryptCookies::class,
    AddQueuedCookiesToResponse::class,
    StartSession::class,
    EnsureTenantSession::class,
    'auth:web',
])->prefix('inventory')->group(function (): void {
    Route::apiResource('categories', CategoryController::class);
    Route::apiResource('units-of-measure', UnitOfMeasureController::class);
    Route::apiResource('products', ProductController::class);
});
