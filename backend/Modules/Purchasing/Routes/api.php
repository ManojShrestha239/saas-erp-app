<?php

declare(strict_types=1);

use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Middleware\EnsureTenantSession;
use Modules\Purchasing\Http\Controllers\DebitNoteController;
use Modules\Purchasing\Http\Controllers\GoodsReceiptController;
use Modules\Purchasing\Http\Controllers\PurchaseOrderController;
use Modules\Purchasing\Http\Controllers\SupplierBillController;

Route::middleware([
    'identify.tenant',
    EncryptCookies::class,
    AddQueuedCookiesToResponse::class,
    StartSession::class,
    EnsureTenantSession::class,
    'auth:web',
])->group(function (): void {
    // Purchase Orders
    Route::get('purchasing/orders', [PurchaseOrderController::class, 'index'])->name('purchasing.orders.index');
    Route::post('purchasing/orders', [PurchaseOrderController::class, 'store'])->name('purchasing.orders.store');
    Route::get('purchasing/orders/{id}', [PurchaseOrderController::class, 'show'])->name('purchasing.orders.show');
    Route::get('purchasing/orders/{id}/pdf', [PurchaseOrderController::class, 'pdf'])->name('purchasing.orders.pdf');
    Route::post('purchasing/orders/{id}/approve', [PurchaseOrderController::class, 'approve'])->name('purchasing.orders.approve');
    Route::post('purchasing/orders/{id}/cancel', [PurchaseOrderController::class, 'cancel'])->name('purchasing.orders.cancel');

    // Goods Receipts
    Route::post('purchasing/orders/{id}/receipts', [GoodsReceiptController::class, 'store'])->name('purchasing.receipts.store');

    // Supplier Bills
    Route::get('purchasing/bills', [SupplierBillController::class, 'index'])->name('purchasing.bills.index');
    Route::post('purchasing/bills', [SupplierBillController::class, 'store'])->name('purchasing.bills.store');
    Route::get('purchasing/bills/{id}', [SupplierBillController::class, 'show'])->name('purchasing.bills.show');
    Route::post('purchasing/bills/{id}/post', [SupplierBillController::class, 'postBill'])->name('purchasing.bills.post');
    Route::get('purchasing/suppliers/{supplierId}/payable-balance', [SupplierBillController::class, 'supplierBalance'])->name('purchasing.suppliers.payable-balance');

    // Debit Notes
    Route::get('purchasing/debit-notes', [DebitNoteController::class, 'index'])->name('purchasing.debit-notes.index');
    Route::post('purchasing/debit-notes', [DebitNoteController::class, 'store'])->name('purchasing.debit-notes.store');
    Route::get('purchasing/debit-notes/{id}', [DebitNoteController::class, 'show'])->name('purchasing.debit-notes.show');
    Route::put('purchasing/debit-notes/{id}', [DebitNoteController::class, 'update'])->name('purchasing.debit-notes.update');
    Route::delete('purchasing/debit-notes/{id}', [DebitNoteController::class, 'destroy'])->name('purchasing.debit-notes.destroy');
    Route::post('purchasing/debit-notes/{id}/post', [DebitNoteController::class, 'post'])->name('purchasing.debit-notes.post');
});
