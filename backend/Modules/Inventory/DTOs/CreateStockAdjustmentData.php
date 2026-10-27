<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

final readonly class CreateStockAdjustmentData
{
    /**
     * @param  list<StockAdjustmentItemData>  $items
     */
    public function __construct(
        public string $warehouseId,
        public string $reason,
        public array $items,
        public ?string $userId = null,
    ) {}
}
