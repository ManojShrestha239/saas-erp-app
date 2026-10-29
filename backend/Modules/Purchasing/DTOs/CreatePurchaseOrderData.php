<?php

declare(strict_types=1);

namespace Modules\Purchasing\DTOs;

final readonly class CreatePurchaseOrderData
{
    /**
     * @param  list<PurchaseOrderItemData>  $items
     */
    public function __construct(
        public string $supplierId,
        public string $warehouseId,
        public string $orderDate,
        public ?string $expectedDeliveryDate = null,
        public string $currency = 'USD',
        public ?string $notes = null,
        public ?string $userId = null,
        public array $items = [],
    ) {}
}
