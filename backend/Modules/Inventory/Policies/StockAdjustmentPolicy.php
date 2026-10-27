<?php

declare(strict_types=1);

namespace Modules\Inventory\Policies;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Modules\Auth\Enums\TenantPermission;
use Modules\Inventory\Models\StockAdjustment;

final class StockAdjustmentPolicy
{
    public function viewAny(Authorizable $user): bool
    {
        return $user->can(TenantPermission::StockView->value);
    }

    public function view(Authorizable $user, StockAdjustment $adjustment): bool
    {
        return $user->can(TenantPermission::StockView->value);
    }

    public function create(Authorizable $user): bool
    {
        return $user->can(TenantPermission::StockAdjust->value)
            || $user->can(TenantPermission::ProductsManage->value);
    }
}
