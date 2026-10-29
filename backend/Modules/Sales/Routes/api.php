<?php

declare(strict_types=1);

use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Middleware\EnsureTenantSession;
use Modules\Sales\Http\Controllers\SalesInvoiceController;
use Modules\Sales\Http\Controllers\SalesQuotationController;

Route::middleware([
    'identify.tenant',
    EncryptCookies::class,
    AddQueuedCookiesToResponse::class,
    StartSession::class,
    EnsureTenantSession::class,
    'auth:web',
])->group(function (): void {
    // Sales Quotations
    Route::get('sales/quotations', [SalesQuotationController::class, 'index'])->name('sales.quotations.index');
    Route::post('sales/quotations', [SalesQuotationController::class, 'store'])->name('sales.quotations.store');
    Route::get('sales/quotations/{id}', [SalesQuotationController::class, 'show'])->name('sales.quotations.show');
    Route::post('sales/quotations/{id}/send', [SalesQuotationController::class, 'send'])->name('sales.quotations.send');
    Route::post('sales/quotations/{id}/accept', [SalesQuotationController::class, 'accept'])->name('sales.quotations.accept');
    Route::post('sales/quotations/{id}/reject', [SalesQuotationController::class, 'reject'])->name('sales.quotations.reject');
    Route::post('sales/quotations/{id}/convert', [SalesQuotationController::class, 'convert'])->name('sales.quotations.convert');

    // Sales Invoices
    Route::get('sales/invoices', [SalesInvoiceController::class, 'index'])->name('sales.invoices.index');
    Route::post('sales/invoices', [SalesInvoiceController::class, 'store'])->name('sales.invoices.store');
    Route::get('sales/invoices/{id}', [SalesInvoiceController::class, 'show'])->name('sales.invoices.show');
    Route::put('sales/invoices/{id}', [SalesInvoiceController::class, 'update'])->name('sales.invoices.update');
    Route::delete('sales/invoices/{id}', [SalesInvoiceController::class, 'destroy'])->name('sales.invoices.destroy');
    Route::post('sales/invoices/{id}/post', [SalesInvoiceController::class, 'post'])->name('sales.invoices.post');
    Route::post('sales/invoices/{id}/void', [SalesInvoiceController::class, 'void'])->name('sales.invoices.void');
    Route::get('sales/customers/{customerId}/receivable-balance', [SalesInvoiceController::class, 'customerReceivableBalance'])->name('sales.customers.receivable-balance');
});
