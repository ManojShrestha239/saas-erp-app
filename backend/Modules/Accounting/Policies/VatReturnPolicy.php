<?php

declare(strict_types=1);

namespace Modules\Accounting\Policies;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Modules\Accounting\Models\VatReturn;
use Modules\Auth\Enums\TenantPermission;

final class VatReturnPolicy
{
    public function viewAny(Authorizable $user): bool
    {
        return $this->canView($user);
    }

    public function view(Authorizable $user, VatReturn $vatReturn): bool
    {
        return $this->canView($user);
    }

    public function create(Authorizable $user): bool
    {
        return $this->canManage($user);
    }

    public function file(Authorizable $user): bool
    {
        return $this->canManage($user);
    }

    public function delete(Authorizable $user, VatReturn $vatReturn): bool
    {
        return $this->canManage($user);
    }

    private function canView(Authorizable $user): bool
    {
        return method_exists($user, 'hasPermissionTo')
            && ($user->hasPermissionTo(TenantPermission::AccountingManage->value)
                || $user->hasPermissionTo(TenantPermission::ReportsFinancial->value));
    }

    private function canManage(Authorizable $user): bool
    {
        return method_exists($user, 'hasPermissionTo')
            && $user->hasPermissionTo(TenantPermission::AccountingManage->value);
    }
}
