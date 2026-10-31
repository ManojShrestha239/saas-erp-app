<?php

declare(strict_types=1);

namespace Modules\Accounting\Policies;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Modules\Accounting\Models\Account;
use Modules\Auth\Enums\TenantPermission;

final class AccountPolicy
{
    public function viewAny(Authorizable $user): bool
    {
        return $this->canView($user);
    }

    public function view(Authorizable $user, Account $account): bool
    {
        return $this->canView($user);
    }

    public function create(Authorizable $user): bool
    {
        return $this->canManage($user);
    }

    public function update(Authorizable $user, Account $account): bool
    {
        return $this->canManage($user);
    }

    public function delete(Authorizable $user, Account $account): bool
    {
        return $this->canManage($user);
    }

    private function canView(Authorizable $user): bool
    {
        return $user->can(TenantPermission::AccountingManage->value)
            || $user->can(TenantPermission::ReportsFinancial->value);
    }

    private function canManage(Authorizable $user): bool
    {
        return $user->can(TenantPermission::AccountingManage->value);
    }
}
