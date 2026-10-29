<?php

declare(strict_types=1);

namespace Modules\Purchasing\DTOs;

final readonly class SupplierBillItemData
{
    public function __construct(
        public string $description,
        public int $quantity,
        public int $unitCostCents,
        public int $taxRatePercent = 0,
        public ?string $purchaseOrderItemId = null,
        public ?string $productId = null,
    ) {}
}
