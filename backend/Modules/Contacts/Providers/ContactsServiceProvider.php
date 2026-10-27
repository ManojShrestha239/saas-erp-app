<?php

declare(strict_types=1);

namespace Modules\Contacts\Providers;

use Illuminate\Support\Facades\Gate;
use Modules\Contacts\Models\Contact;
use Modules\Contacts\Policies\ContactPolicy;
use Shared\Providers\ModuleServiceProvider;

final class ContactsServiceProvider extends ModuleServiceProvider
{
    protected function getModuleName(): string
    {
        return 'Contacts';
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(Contact::class, ContactPolicy::class);
    }
}
