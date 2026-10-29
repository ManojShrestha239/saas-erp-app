<?php

declare(strict_types=1);

namespace Modules\Purchasing\DTOs;

final readonly class CreateSupplierBillData
{
    /**
     * @param  list<SupplierBillItemData>  $items
     */
    public function __construct(
        public string $supplierId,
        public string $billDate,
        public string $dueDate,
        public ?string $supplierInvoiceNumber = null,
        public ?string $purchaseOrderId = null,
        public string $currency = 'USD',
        public ?string $notes = null,
        public ?string $userId = null,
        public array $items = [],
    ) {}
}
