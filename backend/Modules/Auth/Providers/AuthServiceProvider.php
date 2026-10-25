<?php

declare(strict_types=1);

namespace Modules\Auth\Providers;

use Shared\Providers\ModuleServiceProvider;

final class AuthServiceProvider extends ModuleServiceProvider
{
    protected function getModuleName(): string
    {
        return 'Auth';
    }
}
