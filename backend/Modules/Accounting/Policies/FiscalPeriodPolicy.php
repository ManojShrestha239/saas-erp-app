<?php

declare(strict_types=1);

namespace Modules\Accounting\Policies;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Modules\Accounting\Models\FiscalPeriod;
use Modules\Auth\Enums\TenantPermission;

final class FiscalPeriodPolicy
{
    public function viewAny(Authorizable $user): bool
    {
        return $this->canView($user);
    }

    public function view(Authorizable $user, FiscalPeriod $period): bool
    {
        return $this->canView($user);
    }

    public function create(Authorizable $user): bool
    {
        return $this->canManage($user);
    }

    public function lock(Authorizable $user, FiscalPeriod $period): bool
    {
        return $this->canManage($user);
    }

    public function unlock(Authorizable $user, FiscalPeriod $period): bool
    {
        return $this->canManage($user);
    }

    public function close(Authorizable $user, FiscalPeriod $period): bool
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
