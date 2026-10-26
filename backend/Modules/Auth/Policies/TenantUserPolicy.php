<?php

declare(strict_types=1);

namespace Modules\Auth\Policies;

use Modules\Auth\Enums\TenantPermission;
use Modules\Auth\Models\TenantUser;

final class TenantUserPolicy
{
    public function viewAny(TenantUser $user): bool
    {
        return $user->can(TenantPermission::UsersManage->value);
    }

    public function create(TenantUser $user): bool
    {
        return $user->can(TenantPermission::UsersManage->value);
    }

    public function updateRole(TenantUser $user, TenantUser $targetUser): bool
    {
        return $user->can(TenantPermission::UsersManage->value);
    }

    public function updateStatus(TenantUser $user, TenantUser $targetUser): bool
    {
        if ($user->id === $targetUser->id) {
            return false;
        }

        return $user->can(TenantPermission::UsersManage->value);
    }
}
