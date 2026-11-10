<?php

declare(strict_types=1);

namespace Modules\Reports\Providers;

use Shared\Providers\ModuleServiceProvider;

final class ReportsServiceProvider extends ModuleServiceProvider
{
    protected function getModuleName(): string
    {
        return 'Reports';
    }

    public function register(): void
    {
        // Reports module coordinates public services from Accounting, Contacts, and Inventory
    }
}
