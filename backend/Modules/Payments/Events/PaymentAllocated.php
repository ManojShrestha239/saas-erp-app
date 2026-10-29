<?php

declare(strict_types=1);

namespace Modules\Payments\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Payments\Models\Payment;
use Modules\Payments\Models\PaymentAllocation;

final class PaymentAllocated implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Payment $payment,
        public PaymentAllocation $allocation,
        public string $tenantId,
        public string $userId,
    ) {}
}
