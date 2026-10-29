<?php

declare(strict_types=1);

namespace Modules\Purchasing\DTOs;

final readonly class RecordGoodsReceiptData
{
    /**
     * @param  list<GoodsReceiptItemData>  $items
     */
    public function __construct(
        public string $purchaseOrderId,
        public string $receivedDate,
        public ?string $notes = null,
        public ?string $userId = null,
        public array $items = [],
    ) {}
}
