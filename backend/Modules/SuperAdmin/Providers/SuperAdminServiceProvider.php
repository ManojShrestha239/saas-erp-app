<?php

declare(strict_types=1);

namespace Modules\SuperAdmin\Providers;

use Shared\Providers\ModuleServiceProvider;

final class SuperAdminServiceProvider extends ModuleServiceProvider
{
    protected function getModuleName(): string
    {
        return 'SuperAdmin';
    }
}
