<?php

declare(strict_types=1);

namespace Modules\Tenancy\Providers;

use Shared\Providers\ModuleServiceProvider;

final class TenancyServiceProvider extends ModuleServiceProvider
{
    protected function getModuleName(): string
    {
        return 'Tenancy';
    }
}
