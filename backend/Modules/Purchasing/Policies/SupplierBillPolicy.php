<?php

declare(strict_types=1);

namespace Modules\Purchasing\Policies;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Modules\Auth\Enums\TenantPermission;
use Modules\Purchasing\Models\SupplierBill;

final class SupplierBillPolicy
{
    public function viewAny(Authorizable $user): bool
    {
        return $this->canManageBills($user);
    }

    public function view(Authorizable $user, SupplierBill $bill): bool
    {
        return $this->canManageBills($user);
    }

    public function create(Authorizable $user): bool
    {
        return $this->canManageBills($user);
    }

    public function post(Authorizable $user, SupplierBill $bill): bool
    {
        return $this->canManageBills($user);
    }

    private function canManageBills(Authorizable $user): bool
    {
        return $user->can(TenantPermission::PurchasesManage->value);
    }
}
