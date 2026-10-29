<?php

declare(strict_types=1);

namespace Modules\Payments\Policies;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Modules\Auth\Enums\TenantPermission;
use Modules\Payments\Enums\PaymentType;
use Modules\Payments\Models\Payment;

final class PaymentPolicy
{
    public function viewAny(Authorizable $user): bool
    {
        return $user->can('payments.manage')
            || $user->can(TenantPermission::PaymentsCustomerReceipts->value)
            || $user->can(TenantPermission::PaymentsSupplierBills->value)
            || $user->can('sales.manage')
            || $user->can('purchases.manage');
    }

    public function view(Authorizable $user, Payment $payment): bool
    {
        if ($user->can('payments.manage')) {
            return true;
        }

        if ($payment->type === PaymentType::Inbound) {
            return $user->can(TenantPermission::PaymentsCustomerReceipts->value)
                || $user->can('sales.manage')
                || $user->can('customers.manage');
        }

        return $user->can(TenantPermission::PaymentsSupplierBills->value)
            || $user->can('purchases.manage')
            || $user->can('suppliers.manage');
    }

    public function create(Authorizable $user): bool
    {
        return $user->can('payments.manage')
            || $user->can(TenantPermission::PaymentsCustomerReceipts->value)
            || $user->can(TenantPermission::PaymentsSupplierBills->value)
            || $user->can('sales.manage')
            || $user->can('purchases.manage');
    }

    public function post(Authorizable $user, Payment $payment): bool
    {
        if ($user->can('payments.manage')) {
            return true;
        }

        if ($payment->type === PaymentType::Inbound) {
            return $user->can('sales.manage') || $user->can(TenantPermission::PaymentsCustomerReceipts->value);
        }

        return $user->can('purchases.manage') || $user->can(TenantPermission::PaymentsSupplierBills->value);
    }

    public function allocate(Authorizable $user, Payment $payment): bool
    {
        if ($user->can('payments.manage')) {
            return true;
        }

        if ($payment->type === PaymentType::Inbound) {
            return $user->can('sales.manage') || $user->can(TenantPermission::PaymentsCustomerReceipts->value);
        }

        return $user->can('purchases.manage') || $user->can(TenantPermission::PaymentsSupplierBills->value);
    }

    public function void(Authorizable $user, Payment $payment): bool
    {
        return $user->can('payments.manage');
    }
}
