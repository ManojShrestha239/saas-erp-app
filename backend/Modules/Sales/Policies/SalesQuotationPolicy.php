<?php

declare(strict_types=1);

namespace Modules\Sales\Policies;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Modules\Auth\Enums\TenantPermission;
use Modules\Sales\Models\SalesQuotation;

final class SalesQuotationPolicy
{
    public function viewAny(Authorizable $user): bool
    {
        return $this->canAccess($user);
    }

    public function view(Authorizable $user, SalesQuotation $quotation): bool
    {
        return $this->canAccess($user);
    }

    public function create(Authorizable $user): bool
    {
        return $this->canManage($user);
    }

    public function update(Authorizable $user, SalesQuotation $quotation): bool
    {
        return $this->canManage($user);
    }

    public function delete(Authorizable $user, SalesQuotation $quotation): bool
    {
        return $this->canManage($user);
    }

    public function send(Authorizable $user, SalesQuotation $quotation): bool
    {
        return $this->canManage($user);
    }

    public function accept(Authorizable $user, SalesQuotation $quotation): bool
    {
        return $this->canManage($user);
    }

    public function reject(Authorizable $user, SalesQuotation $quotation): bool
    {
        return $this->canManage($user);
    }

    public function convert(Authorizable $user, SalesQuotation $quotation): bool
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
