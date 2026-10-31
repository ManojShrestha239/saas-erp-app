<?php

declare(strict_types=1);

namespace Modules\Accounting\Providers;

use Illuminate\Support\Facades\Gate;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Policies\AccountPolicy;
use Modules\Accounting\Services\AccountService;
use Shared\Providers\ModuleServiceProvider;

final class AccountingServiceProvider extends ModuleServiceProvider
{
    protected function getModuleName(): string
    {
        return 'Accounting';
    }

    public function register(): void
    {
        $this->app->singleton(AccountService::class);
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(Account::class, AccountPolicy::class);
    }
}
