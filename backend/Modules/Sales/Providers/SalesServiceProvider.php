<?php

declare(strict_types=1);

namespace Modules\Sales\Providers;

use Illuminate\Support\Facades\Gate;
use Modules\Sales\Models\CreditNote;
use Modules\Sales\Models\SalesInvoice;
use Modules\Sales\Models\SalesQuotation;
use Modules\Sales\Policies\CreditNotePolicy;
use Modules\Sales\Policies\SalesInvoicePolicy;
use Modules\Sales\Policies\SalesQuotationPolicy;
use Shared\Providers\ModuleServiceProvider;

final class SalesServiceProvider extends ModuleServiceProvider
{
    protected function getModuleName(): string
    {
        return 'Sales';
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(SalesQuotation::class, SalesQuotationPolicy::class);
        Gate::policy(SalesInvoice::class, SalesInvoicePolicy::class);
        Gate::policy(CreditNote::class, CreditNotePolicy::class);
    }
}
