<?php

declare(strict_types=1);

namespace Modules\Purchasing\Policies;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Modules\Auth\Enums\TenantPermission;
use Modules\Purchasing\Models\PurchaseOrder;

final class PurchaseOrderPolicy
{
    public function viewAny(Authorizable $user): bool
    {
        return $this->canAccess($user);
    }

    public function view(Authorizable $user, PurchaseOrder $po): bool
    {
        return $this->canAccess($user);
    }

    public function create(Authorizable $user): bool
    {
        return $this->canDraft($user);
    }

    public function approve(Authorizable $user, PurchaseOrder $po): bool
    {
        return $this->canManage($user);
    }

    public function cancel(Authorizable $user, PurchaseOrder $po): bool
    {
        return $this->canManage($user);
    }

    public function receiveGoods(Authorizable $user, PurchaseOrder $po): bool
    {
        return $user->can(TenantPermission::PurchasesManage->value)
            || $user->can(TenantPermission::PurchasesPoDraft->value)
            || $user->can(TenantPermission::StockAdjust->value);
    }

    private function canAccess(Authorizable $user): bool
    {
        return $user->can(TenantPermission::PurchasesManage->value)
            || $user->can(TenantPermission::PurchasesPoDraft->value);
    }

    private function canDraft(Authorizable $user): bool
    {
        return $user->can(TenantPermission::PurchasesManage->value)
            || $user->can(TenantPermission::PurchasesPoDraft->value);
    }

    private function canManage(Authorizable $user): bool
    {
        return $user->can(TenantPermission::PurchasesManage->value);
    }
}
