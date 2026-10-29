<?php

declare(strict_types=1);

namespace Modules\Purchasing\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Purchasing\Models\PurchaseOrder;

final class PurchaseOrderApproved
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly PurchaseOrder $purchaseOrder,
        public readonly string $userId,
    ) {}
}
