<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Audit\Http\Controllers\AuditLogWebController;
use Modules\Auth\Http\Middleware\EnsureTenantSession;

Route::middleware([
    'identify.tenant',
    EnsureTenantSession::class,
    'auth:web',
    'can:audit.view',
])->group(function (): void {
    Route::get('audit-logs', [AuditLogWebController::class, 'index'])->name('audit-logs.web.index');
});
