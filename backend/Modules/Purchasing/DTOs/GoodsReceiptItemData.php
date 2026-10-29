<?php

declare(strict_types=1);

namespace Modules\Purchasing\DTOs;

final readonly class GoodsReceiptItemData
{
    public function __construct(
        public string $purchaseOrderItemId,
        public int $quantityReceived,
    ) {}
}
