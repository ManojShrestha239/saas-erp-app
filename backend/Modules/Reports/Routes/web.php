<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Middleware\EnsureTenantSession;
use Modules\Reports\Http\Controllers\ReportsWebController;

Route::middleware([
    'identify.tenant',
    EnsureTenantSession::class,
    'auth:web',
])->group(function (): void {
    Route::get('reports', [ReportsWebController::class, 'index'])->name('reports.web.index');
});
