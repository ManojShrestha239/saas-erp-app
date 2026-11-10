<?php

declare(strict_types=1);

namespace Modules\Audit\Policies;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Modules\Audit\Models\TenantActivity;
use Modules\Auth\Enums\TenantPermission;

final class AuditLogPolicy
{
    public function viewAny(Authorizable $user): bool
    {
        return $user->can(TenantPermission::AuditView->value);
    }

    public function view(Authorizable $user, TenantActivity $activity): bool
    {
        return $user->can(TenantPermission::AuditView->value);
    }

    public function create(Authorizable $user): bool
    {
        return false;
    }

    public function update(Authorizable $user, TenantActivity $activity): bool
    {
        return false;
    }

    public function delete(Authorizable $user, TenantActivity $activity): bool
    {
        return false;
    }
}
