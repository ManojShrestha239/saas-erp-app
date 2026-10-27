<?php

declare(strict_types=1);

namespace Modules\Inventory\Policies;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Modules\Auth\Enums\TenantPermission;
use Modules\Inventory\Models\Warehouse;

final class WarehousePolicy
{
    public function viewAny(Authorizable $user): bool
    {
        return $user->can(TenantPermission::StockView->value)
            || $user->can(TenantPermission::ProductsView->value);
    }

    public function view(Authorizable $user, Warehouse $warehouse): bool
    {
        return $user->can(TenantPermission::StockView->value)
            || $user->can(TenantPermission::ProductsView->value);
    }

    public function create(Authorizable $user): bool
    {
        return $user->can(TenantPermission::ProductsManage->value)
            || $user->can(TenantPermission::SettingsManage->value);
    }

    public function update(Authorizable $user, Warehouse $warehouse): bool
    {
        return $user->can(TenantPermission::ProductsManage->value)
            || $user->can(TenantPermission::SettingsManage->value);
    }

    public function delete(Authorizable $user, Warehouse $warehouse): bool
    {
        return $user->can(TenantPermission::ProductsManage->value)
            || $user->can(TenantPermission::SettingsManage->value);
    }
}
