<?php

declare(strict_types=1);

namespace Modules\Sales\Policies;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Modules\Auth\Enums\TenantPermission;
use Modules\Sales\Models\SalesInvoice;

final class SalesInvoicePolicy
{
    public function viewAny(Authorizable $user): bool
    {
        return $this->canAccess($user);
    }

    public function view(Authorizable $user, SalesInvoice $invoice): bool
    {
        return $this->canAccess($user);
    }

    public function create(Authorizable $user): bool
    {
        return $this->canManage($user);
    }

    public function update(Authorizable $user, SalesInvoice $invoice): bool
    {
        return $this->canManage($user);
    }

    public function delete(Authorizable $user, SalesInvoice $invoice): bool
    {
        return $this->canManage($user);
    }

    public function post(Authorizable $user, SalesInvoice $invoice): bool
    {
        return $this->canManage($user);
    }

    private function canAccess(Authorizable $user): bool
    {
        return $user->can(TenantPermission::SalesManage->value);
    }

    private function canManage(Authorizable $user): bool
    {
        return $user->can(TenantPermission::SalesManage->value);
    }
}
