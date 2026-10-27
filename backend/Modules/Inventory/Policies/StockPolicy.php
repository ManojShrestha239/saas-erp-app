<?php

declare(strict_types=1);

namespace Modules\Inventory\Policies;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Modules\Auth\Enums\TenantPermission;

final class StockPolicy
{
    public function viewAny(Authorizable $user): bool
    {
        return $user->can(TenantPermission::StockView->value);
    }

    public function view(Authorizable $user): bool
    {
        return $user->can(TenantPermission::StockView->value);
    }

    public function recordMovement(Authorizable $user): bool
    {
        return $user->can(TenantPermission::StockAdjust->value)
            || $user->can(TenantPermission::ProductsManage->value);
    }
}
