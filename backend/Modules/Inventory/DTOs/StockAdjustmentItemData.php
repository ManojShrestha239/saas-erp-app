<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

final readonly class StockAdjustmentItemData
{
    public function __construct(
        public string $productId,
        public int $quantityDelta,
        public ?int $unitCostCents = null,
    ) {}
}
