<?php

declare(strict_types=1);

namespace Modules\Purchasing\Policies;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Modules\Auth\Enums\TenantPermission;
use Modules\Purchasing\Models\DebitNote;

final class DebitNotePolicy
{
    public function viewAny(Authorizable $user): bool
    {
        return $this->canAccess($user);
    }

    public function view(Authorizable $user, DebitNote $debitNote): bool
    {
        return $this->canAccess($user);
    }

    public function create(Authorizable $user): bool
    {
        return $this->canManage($user);
    }

    public function update(Authorizable $user, DebitNote $debitNote): bool
    {
        return $this->canManage($user);
    }

    public function delete(Authorizable $user, DebitNote $debitNote): bool
    {
        return $this->canManage($user);
    }

    public function post(Authorizable $user, DebitNote $debitNote): bool
    {
        return $this->canManage($user);
    }

    private function canAccess(Authorizable $user): bool
    {
        return $user->can(TenantPermission::PurchasesManage->value)
            || $user->can(TenantPermission::AccountingManage->value);
    }

    private function canManage(Authorizable $user): bool
    {
        return $user->can(TenantPermission::PurchasesManage->value)
            || $user->can(TenantPermission::AccountingManage->value);
    }
}
