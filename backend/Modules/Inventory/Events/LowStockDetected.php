<?php

declare(strict_types=1);

namespace Modules\Inventory\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class LowStockDetected implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $productId,
        public readonly string $productName,
        public readonly string $sku,
        public readonly string $warehouseId,
        public readonly string $warehouseName,
        public readonly int $quantityOnHand,
        public readonly int $lowStockThreshold,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toStreamPayload(): array
    {
        return [
            'type' => 'low_stock_alert',
            'product_id' => $this->productId,
            'product_name' => $this->productName,
            'sku' => $this->sku,
            'warehouse_id' => $this->warehouseId,
            'warehouse_name' => $this->warehouseName,
            'quantity_on_hand' => $this->quantityOnHand,
            'low_stock_threshold' => $this->lowStockThreshold,
            'deficit' => max(0, $this->lowStockThreshold - $this->quantityOnHand),
            'timestamp' => now()->toISOString(),
        ];
    }
}
