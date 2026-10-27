<?php

declare(strict_types=1);

namespace Modules\Contacts\Policies;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Modules\Auth\Enums\TenantPermission;
use Modules\Contacts\Enums\ContactType;
use Modules\Contacts\Models\Contact;

final class ContactPolicy
{
    public function viewAny(Authorizable $user): bool
    {
        return $user->can(TenantPermission::CustomersManage->value)
            || $user->can(TenantPermission::SuppliersManage->value)
            || $user->can(TenantPermission::SalesManage->value)
            || $user->can(TenantPermission::PurchasesManage->value);
    }

    public function view(Authorizable $user, Contact $contact): bool
    {
        return match ($contact->type) {
            ContactType::Customer => $user->can(TenantPermission::CustomersManage->value)
                || $user->can(TenantPermission::SalesManage->value),
            ContactType::Supplier => $user->can(TenantPermission::SuppliersManage->value)
                || $user->can(TenantPermission::PurchasesManage->value),
            ContactType::Both => $user->can(TenantPermission::CustomersManage->value)
                || $user->can(TenantPermission::SuppliersManage->value)
                || $user->can(TenantPermission::SalesManage->value)
                || $user->can(TenantPermission::PurchasesManage->value),
        };
    }

    public function create(Authorizable $user, ?string $type = null): bool
    {
        if ($type === ContactType::Customer->value) {
            return $user->can(TenantPermission::CustomersManage->value)
                || $user->can(TenantPermission::SalesManage->value);
        }

        if ($type === ContactType::Supplier->value) {
            return $user->can(TenantPermission::SuppliersManage->value)
                || $user->can(TenantPermission::PurchasesManage->value);
        }

        return $user->can(TenantPermission::CustomersManage->value)
            || $user->can(TenantPermission::SuppliersManage->value)
            || $user->can(TenantPermission::SalesManage->value)
            || $user->can(TenantPermission::PurchasesManage->value);
    }

    public function update(Authorizable $user, Contact $contact): bool
    {
        return match ($contact->type) {
            ContactType::Customer => $user->can(TenantPermission::CustomersManage->value)
                || $user->can(TenantPermission::SalesManage->value),
            ContactType::Supplier => $user->can(TenantPermission::SuppliersManage->value)
                || $user->can(TenantPermission::PurchasesManage->value),
            ContactType::Both => $user->can(TenantPermission::CustomersManage->value)
                || $user->can(TenantPermission::SuppliersManage->value),
        };
    }

    public function delete(Authorizable $user, Contact $contact): bool
    {
        return match ($contact->type) {
            ContactType::Customer => $user->can(TenantPermission::CustomersManage->value),
            ContactType::Supplier => $user->can(TenantPermission::SuppliersManage->value),
            ContactType::Both => $user->can(TenantPermission::CustomersManage->value)
                || $user->can(TenantPermission::SuppliersManage->value),
        };
    }
}
