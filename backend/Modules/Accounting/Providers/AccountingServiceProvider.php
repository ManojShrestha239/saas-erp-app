<?php

declare(strict_types=1);

namespace Modules\Accounting\Providers;

use Illuminate\Support\Facades\Gate;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\FiscalPeriod;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Policies\AccountPolicy;
use Modules\Accounting\Policies\FiscalPeriodPolicy;
use Modules\Accounting\Policies\JournalEntryPolicy;
use Modules\Accounting\Services\AccountService;
use Modules\Accounting\Services\DocumentPostingService;
use Modules\Accounting\Services\FiscalPeriodService;
use Modules\Accounting\Services\JournalEntryService;
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
        $this->app->singleton(FiscalPeriodService::class);
        $this->app->singleton(JournalEntryService::class);
        $this->app->singleton(DocumentPostingService::class);
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(Account::class, AccountPolicy::class);
        Gate::policy(FiscalPeriod::class, FiscalPeriodPolicy::class);
        Gate::policy(JournalEntry::class, JournalEntryPolicy::class);
    }
}
