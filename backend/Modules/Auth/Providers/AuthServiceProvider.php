<?php

declare(strict_types=1);

namespace Modules\Auth\Providers;

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
}
