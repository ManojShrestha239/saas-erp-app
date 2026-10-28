<?php

declare(strict_types=1);

use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Middleware\EnsureTenantSession;
use Modules\Contacts\Http\Controllers\ContactController;
use Modules\Contacts\Http\Controllers\ContactCsvImportController;

Route::middleware([
    'identify.tenant',
    EncryptCookies::class,
    AddQueuedCookiesToResponse::class,
    StartSession::class,
    EnsureTenantSession::class,
    'auth:web',
])->group(function (): void {
    Route::get('contacts/import/template', [ContactCsvImportController::class, 'template'])->name('contacts.import.template');
    Route::post('contacts/import', [ContactCsvImportController::class, 'store'])->name('contacts.import.store');
    Route::get('contacts/import/{id}', [ContactCsvImportController::class, 'show'])->name('contacts.import.show');
    Route::apiResource('contacts', ContactController::class);
});
