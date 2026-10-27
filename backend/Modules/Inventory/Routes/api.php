<?php

declare(strict_types=1);

use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Middleware\EnsureTenantSession;
use Modules\Inventory\Http\Controllers\CategoryController;
use Modules\Inventory\Http\Controllers\ProductController;
use Modules\Inventory\Http\Controllers\StockAdjustmentController;
use Modules\Inventory\Http\Controllers\StockController;
use Modules\Inventory\Http\Controllers\UnitOfMeasureController;
use Modules\Inventory\Http\Controllers\WarehouseController;

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
    Route::apiResource('warehouses', WarehouseController::class);

    Route::get('stock-levels', [StockController::class, 'levels'])->name('stock-levels.index');
    Route::get('stock-levels/{warehouse}/{product}', [StockController::class, 'levelDetail'])->name('stock-levels.detail');
    Route::get('stock-movements', [StockController::class, 'movements'])->name('stock-movements.index');
    Route::post('stock-movements', [StockController::class, 'recordMovement'])->name('stock-movements.store');

    Route::apiResource('adjustments', StockAdjustmentController::class)->only(['index', 'show', 'store']);
});
