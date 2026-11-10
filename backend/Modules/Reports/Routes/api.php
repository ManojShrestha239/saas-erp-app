<?php

declare(strict_types=1);

use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Middleware\EnsureTenantSession;
use Modules\Reports\Http\Controllers\FinancialReportController;
use Modules\Reports\Http\Controllers\OperationalReportController;

Route::middleware([
    'identify.tenant',
    EncryptCookies::class,
    AddQueuedCookiesToResponse::class,
    StartSession::class,
    EnsureTenantSession::class,
    'auth:web',
])->group(function (): void {
    // Financial Reports & Aging
    Route::middleware('can:reports.financial')->group(function (): void {
        Route::get('reports/trial-balance', [FinancialReportController::class, 'trialBalance'])->name('reports.trial-balance');
        Route::get('reports/profit-and-loss', [FinancialReportController::class, 'profitAndLoss'])->name('reports.profit-and-loss');
        Route::get('reports/balance-sheet', [FinancialReportController::class, 'balanceSheet'])->name('reports.balance-sheet');
        Route::get('reports/ar-aging', [OperationalReportController::class, 'arAging'])->name('reports.ar-aging');
        Route::get('reports/ap-aging', [OperationalReportController::class, 'apAging'])->name('reports.ap-aging');
    });

    // Inventory / Stock Reports
    Route::middleware('can:reports.stock')->group(function (): void {
        Route::get('reports/stock-valuation', [OperationalReportController::class, 'stockValuation'])->name('reports.stock-valuation');
        Route::get('reports/stock-movements', [OperationalReportController::class, 'stockMovement'])->name('reports.stock-movements');
    });
});
