<?php

declare(strict_types=1);

namespace Modules\Purchasing\Providers;

use Illuminate\Support\Facades\Gate;
use Modules\Purchasing\Models\PurchaseOrder;
use Modules\Purchasing\Models\SupplierBill;
use Modules\Purchasing\Policies\PurchaseOrderPolicy;
use Modules\Purchasing\Policies\SupplierBillPolicy;
use Shared\Providers\ModuleServiceProvider;

final class PurchasingServiceProvider extends ModuleServiceProvider
{
    protected function getModuleName(): string
    {
        return 'Purchasing';
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(PurchaseOrder::class, PurchaseOrderPolicy::class);
        Gate::policy(SupplierBill::class, SupplierBillPolicy::class);
    }
}
