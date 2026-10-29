<?php

declare(strict_types=1);

namespace Modules\Purchasing\DTOs;

final readonly class PurchaseOrderItemData
{
    public function __construct(
        public string $productId,
        public int $quantityOrdered,
        public int $unitCostCents,
        public int $taxRatePercent = 0,
        public ?string $taxCategory = null,
        public ?string $notes = null,
    ) {}
}
