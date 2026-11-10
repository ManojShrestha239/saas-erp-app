<?php

declare(strict_types=1);

namespace Modules\Contacts\Providers;

use Illuminate\Support\Facades\Gate;
use Modules\Contacts\Models\Contact;
use Modules\Contacts\Policies\ContactPolicy;
use Modules\Contacts\Services\AgingReportService;
use Modules\Contacts\Services\ContactService;
use Modules\Contacts\Services\ContactStatementService;
use Shared\Providers\ModuleServiceProvider;

final class ContactsServiceProvider extends ModuleServiceProvider
{
    protected function getModuleName(): string
    {
        return 'Contacts';
    }

    public function register(): void
    {
        $this->app->singleton(ContactService::class);
        $this->app->singleton(ContactStatementService::class);
        $this->app->singleton(AgingReportService::class);
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(Contact::class, ContactPolicy::class);
    }
}
