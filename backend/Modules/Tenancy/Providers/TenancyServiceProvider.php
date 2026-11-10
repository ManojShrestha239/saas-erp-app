<?php

declare(strict_types=1);

namespace Modules\Tenancy\Providers;

use Modules\Tenancy\Commands\BackupTenantDatabaseCommand;
use Modules\Tenancy\Commands\RestoreDrillCommand;
use Modules\Tenancy\Commands\RestoreTenantDatabaseCommand;
use Modules\Tenancy\Commands\SeedDemoTenantCommand;
use Modules\Tenancy\Commands\TenantsMigrateCommand;
use Modules\Tenancy\Services\TenantManagementService;
use Shared\Providers\ModuleServiceProvider;

final class TenancyServiceProvider extends ModuleServiceProvider
{
    protected function getModuleName(): string
    {
        return 'Tenancy';
    }

    public function register(): void
    {
        parent::register();

        $this->app->singleton(TenantManagementService::class);
    }

    public function boot(): void
    {
        parent::boot();

        if ($this->app->runningInConsole()) {
            $this->commands([
                TenantsMigrateCommand::class,
                SeedDemoTenantCommand::class,
                BackupTenantDatabaseCommand::class,
                RestoreTenantDatabaseCommand::class,
                RestoreDrillCommand::class,
            ]);
        }
    }
}
