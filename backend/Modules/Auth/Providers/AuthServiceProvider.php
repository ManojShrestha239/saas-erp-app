<?php

declare(strict_types=1);

namespace Modules\Auth\Providers;

use Illuminate\Support\Facades\Gate;
use Modules\Auth\Models\TenantUser;
use Modules\Auth\Policies\TenantUserPolicy;
use Modules\Auth\Services\TenantPermissionRegistrar;
use Shared\Providers\ModuleServiceProvider;
use Spatie\Permission\PermissionRegistrar;

final class AuthServiceProvider extends ModuleServiceProvider
{
    protected function getModuleName(): string
    {
        return 'Auth';
    }

    public function register(): void
    {
        parent::register();

        $this->app->singleton(PermissionRegistrar::class, TenantPermissionRegistrar::class);
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(TenantUser::class, TenantUserPolicy::class);
    }
}
