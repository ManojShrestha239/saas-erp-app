<?php

declare(strict_types=1);

namespace Shared\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

abstract class ModuleServiceProvider extends ServiceProvider
{
    abstract protected function getModuleName(): string;

    public function boot(): void
    {
        $this->registerRoutes();
        $this->registerMigrations();
    }

    protected function registerRoutes(): void
    {
        $modulePath = base_path('Modules/'.$this->getModuleName());

        $apiRoutes = $modulePath.'/Routes/api.php';
        if (file_exists($apiRoutes)) {
            Route::prefix('api/v1')
                ->middleware('api')
                ->group($apiRoutes);
        }

        $webRoutes = $modulePath.'/Routes/web.php';
        if (file_exists($webRoutes)) {
            Route::middleware('web')
                ->group($webRoutes);
        }
    }

    protected function registerMigrations(): void
    {
        // Tenant module migrations are deliberately isolated from central migrations.
        // They are executed against the 'tenant' connection per tenant via ProvisionTenantDatabase and TenantsMigrateCommand.
    }
}
