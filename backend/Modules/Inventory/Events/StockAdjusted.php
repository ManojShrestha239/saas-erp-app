<?php

declare(strict_types=1);

namespace Modules\Inventory\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class StockAdjusted
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  list<array{product_id: string, quantity_before: int, quantity_delta: int, quantity_after: int, unit_cost_cents: int}>  $items
     */
    public function __construct(
        public readonly string $stockAdjustmentId,
        public readonly string $warehouseId,
        public readonly string $reason,
        public readonly int $totalCostImpactCents,
        public readonly array $items,
        public readonly ?string $tenantId = null,
    ) {}
}
