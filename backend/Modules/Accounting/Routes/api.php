<?php

declare(strict_types=1);

use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Modules\Accounting\Http\Controllers\AccountController;
use Modules\Accounting\Http\Controllers\FiscalPeriodController;
use Modules\Accounting\Http\Controllers\JournalEntryController;
use Modules\Accounting\Http\Controllers\VatReturnController;
use Modules\Auth\Http\Middleware\EnsureTenantSession;

Route::middleware([
    'identify.tenant',
    EncryptCookies::class,
    AddQueuedCookiesToResponse::class,
    StartSession::class,
    EnsureTenantSession::class,
    'auth:web',
])->group(function (): void {
    // Accounts
    Route::get('accounting/accounts', [AccountController::class, 'index'])->name('accounting.accounts.index');
    Route::post('accounting/accounts', [AccountController::class, 'store'])->name('accounting.accounts.store');
    Route::get('accounting/accounts/{id}', [AccountController::class, 'show'])->name('accounting.accounts.show');
    Route::put('accounting/accounts/{id}', [AccountController::class, 'update'])->name('accounting.accounts.update');
    Route::delete('accounting/accounts/{id}', [AccountController::class, 'destroy'])->name('accounting.accounts.destroy');

    // Journal Entries
    Route::get('accounting/journal-entries', [JournalEntryController::class, 'index'])->name('accounting.journal-entries.index');
    Route::post('accounting/journal-entries', [JournalEntryController::class, 'store'])->name('accounting.journal-entries.store');
    Route::get('accounting/journal-entries/{id}', [JournalEntryController::class, 'show'])->name('accounting.journal-entries.show');
    Route::put('accounting/journal-entries/{id}', [JournalEntryController::class, 'update'])->name('accounting.journal-entries.update');
    Route::delete('accounting/journal-entries/{id}', [JournalEntryController::class, 'destroy'])->name('accounting.journal-entries.destroy');
    Route::post('accounting/journal-entries/{id}/submit', [JournalEntryController::class, 'submit'])->name('accounting.journal-entries.submit');
    Route::post('accounting/journal-entries/{id}/approve', [JournalEntryController::class, 'approve'])->name('accounting.journal-entries.approve');
    Route::post('accounting/journal-entries/{id}/reject', [JournalEntryController::class, 'reject'])->name('accounting.journal-entries.reject');
    Route::post('accounting/journal-entries/{id}/reverse', [JournalEntryController::class, 'reverse'])->name('accounting.journal-entries.reverse');

    // Fiscal Periods
    Route::get('accounting/fiscal-periods', [FiscalPeriodController::class, 'index'])->name('accounting.fiscal-periods.index');
    Route::post('accounting/fiscal-periods', [FiscalPeriodController::class, 'store'])->name('accounting.fiscal-periods.store');
    Route::post('accounting/fiscal-periods/generate', [FiscalPeriodController::class, 'generate'])->name('accounting.fiscal-periods.generate');
    Route::get('accounting/fiscal-periods/{id}', [FiscalPeriodController::class, 'show'])->name('accounting.fiscal-periods.show');
    Route::post('accounting/fiscal-periods/{id}/lock', [FiscalPeriodController::class, 'lock'])->name('accounting.fiscal-periods.lock');
    Route::post('accounting/fiscal-periods/{id}/unlock', [FiscalPeriodController::class, 'unlock'])->name('accounting.fiscal-periods.unlock');
    Route::post('accounting/fiscal-periods/{id}/close', [FiscalPeriodController::class, 'close'])->name('accounting.fiscal-periods.close');

    // VAT Returns
    Route::get('accounting/vat-return/calculate', [VatReturnController::class, 'calculate'])->name('accounting.vat-return.calculate');
    Route::get('accounting/vat-return/pdf', [VatReturnController::class, 'exportPdf'])->name('accounting.vat-return.pdf');
    Route::get('accounting/vat-returns', [VatReturnController::class, 'index'])->name('accounting.vat-returns.index');
    Route::post('accounting/vat-returns', [VatReturnController::class, 'store'])->name('accounting.vat-returns.store');
    Route::get('accounting/vat-returns/{id}', [VatReturnController::class, 'show'])->name('accounting.vat-returns.show');
    Route::delete('accounting/vat-returns/{id}', [VatReturnController::class, 'destroy'])->name('accounting.vat-returns.destroy');
});
