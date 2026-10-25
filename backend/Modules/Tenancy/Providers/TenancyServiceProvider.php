<?php

declare(strict_types=1);

namespace Modules\Tenancy\Providers;

use Modules\Tenancy\Commands\TenantsMigrateCommand;
use Shared\Providers\ModuleServiceProvider;

final class TenancyServiceProvider extends ModuleServiceProvider
{
    protected function getModuleName(): string
    {
        return 'Tenancy';
    }

    public function boot(): void
    {
        parent::boot();

        if ($this->app->runningInConsole()) {
            $this->commands([
                TenantsMigrateCommand::class,
            ]);
        }
    }
}
