<?php

declare(strict_types=1);

namespace Shared\Providers;

use Illuminate\Support\ServiceProvider;
use Shared\Tenancy\TenantManager;

final class SharedServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantManager::class);
        $this->registerModules();
    }

    public function boot(): void
    {
        //
    }

    private function registerModules(): void
    {
        $modulesPath = base_path('Modules');
        if (! is_dir($modulesPath)) {
            return;
        }

        $directories = glob($modulesPath.'/*', GLOB_ONLYDIR);
        if ($directories === false) {
            return;
        }

        foreach ($directories as $dir) {
            $moduleName = basename($dir);
            /** @var class-string<ServiceProvider> $providerClass */
            $providerClass = "Modules\\{$moduleName}\\Providers\\{$moduleName}ServiceProvider";

            if (class_exists($providerClass)) {
                $this->app->register($providerClass);
            }
        }
    }
}
