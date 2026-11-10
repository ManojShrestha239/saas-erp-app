<?php

declare(strict_types=1);

namespace Modules\Audit\Providers;

use Illuminate\Support\Facades\Gate;
use Modules\Audit\Models\TenantActivity;
use Modules\Audit\Policies\AuditLogPolicy;
use Modules\Audit\Services\AuditLogService;
use Shared\Providers\ModuleServiceProvider;

final class AuditServiceProvider extends ModuleServiceProvider
{
    protected function getModuleName(): string
    {
        return 'Audit';
    }

    public function register(): void
    {
        $this->app->singleton(AuditLogService::class);
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(TenantActivity::class, AuditLogPolicy::class);
    }
}
