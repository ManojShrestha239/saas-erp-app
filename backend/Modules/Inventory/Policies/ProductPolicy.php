<?php

declare(strict_types=1);

namespace Modules\Inventory\Policies;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Modules\Auth\Enums\TenantPermission;
use Modules\Inventory\Models\Product;

final class ProductPolicy
{
    public function viewAny(Authorizable $user): bool
    {
        return $user->can(TenantPermission::ProductsView->value);
    }

    public function view(Authorizable $user, Product $product): bool
    {
        return $user->can(TenantPermission::ProductsView->value);
    }

    public function create(Authorizable $user): bool
    {
        return $user->can(TenantPermission::ProductsManage->value);
    }

    public function update(Authorizable $user, Product $product): bool
    {
        return $user->can(TenantPermission::ProductsManage->value);
    }

    public function delete(Authorizable $user, Product $product): bool
    {
        return $user->can(TenantPermission::ProductsManage->value);
    }
}
