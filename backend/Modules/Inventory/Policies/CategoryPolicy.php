<?php

declare(strict_types=1);

namespace Modules\Inventory\Policies;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Modules\Auth\Enums\TenantPermission;
use Modules\Inventory\Models\Category;

final class CategoryPolicy
{
    public function viewAny(Authorizable $user): bool
    {
        return $user->can(TenantPermission::ProductsView->value);
    }

    public function view(Authorizable $user, Category $category): bool
    {
        return $user->can(TenantPermission::ProductsView->value);
    }

    public function create(Authorizable $user): bool
    {
        return $user->can(TenantPermission::ProductsManage->value);
    }

    public function update(Authorizable $user, Category $category): bool
    {
        return $user->can(TenantPermission::ProductsManage->value);
    }

    public function delete(Authorizable $user, Category $category): bool
    {
        return $user->can(TenantPermission::ProductsManage->value);
    }
}
