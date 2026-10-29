<?php

declare(strict_types=1);

namespace Modules\Payments\Providers;

use Illuminate\Support\Facades\Gate;
use Modules\Payments\Models\Payment;
use Modules\Payments\Policies\PaymentPolicy;
use Shared\Providers\ModuleServiceProvider;

final class PaymentsServiceProvider extends ModuleServiceProvider
{
    protected function getModuleName(): string
    {
        return 'Payments';
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(Payment::class, PaymentPolicy::class);
    }
}
