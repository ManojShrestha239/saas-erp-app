<?php

declare(strict_types=1);

namespace Modules\SuperAdmin\Providers;

use Modules\SuperAdmin\Services\SuperAdminDashboardService;
use Modules\SuperAdmin\Services\SuperAdminFeatureFlagService;
use Modules\SuperAdmin\Services\SuperAdminPackageService;
use Modules\SuperAdmin\Services\SuperAdminSubscriptionService;
use Shared\Providers\ModuleServiceProvider;

final class SuperAdminServiceProvider extends ModuleServiceProvider
{
    protected function getModuleName(): string
    {
        return 'SuperAdmin';
    }

    public function register(): void
    {
        parent::register();

        $this->app->singleton(SuperAdminPackageService::class);
        $this->app->singleton(SuperAdminSubscriptionService::class);
        $this->app->singleton(SuperAdminFeatureFlagService::class);
        $this->app->singleton(SuperAdminDashboardService::class);
    }
}
