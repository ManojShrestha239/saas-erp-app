<?php

declare(strict_types=1);

namespace Modules\Purchasing\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Purchasing\Models\SupplierBill;

final class SupplierBillPosted implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly SupplierBill $bill,
        public readonly string $tenantId,
        public readonly string $userId,
    ) {}
}
