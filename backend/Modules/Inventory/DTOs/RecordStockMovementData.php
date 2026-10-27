<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

use Modules\Inventory\Enums\StockMovementType;

final readonly class RecordStockMovementData
{
    public function __construct(
        public string $warehouseId,
        public string $productId,
        public StockMovementType $type,
        public int $quantity,
        public int $unitCostCents = 0,
        public ?string $sourceType = null,
        public ?string $sourceId = null,
        public ?string $userId = null,
        public ?string $notes = null,
    ) {}

    /**
     * Get the signed quantity delta based on the movement type.
     */
    public function getSignedQuantity(): int
    {
        return $this->type->isAddition()
            ? abs($this->quantity)
            : -abs($this->quantity);
    }
}
