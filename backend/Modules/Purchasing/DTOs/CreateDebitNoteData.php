<?php

declare(strict_types=1);

namespace Modules\Purchasing\DTOs;

final readonly class CreateDebitNoteData
{
    /**
     * @param  list<DebitNoteItemData>  $items
     */
    public function __construct(
        public string $supplierId,
        public string $warehouseId,
        public string $issueDate,
        public ?string $supplierBillId = null,
        public string $currency = 'USD',
        public bool $returnStock = true,
        public string $reason = 'supplier_return',
        public ?string $notes = null,
        public array $items = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $rawItems = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];
        $items = array_map(
            /** @param array<string, mixed> $item */
            static fn (array $item): DebitNoteItemData => DebitNoteItemData::fromArray($item),
            $rawItems
        );

        return new self(
            supplierId: (string) $data['supplier_id'],
            warehouseId: (string) $data['warehouse_id'],
            issueDate: (string) $data['issue_date'],
            supplierBillId: isset($data['supplier_bill_id']) && $data['supplier_bill_id'] !== '' ? (string) $data['supplier_bill_id'] : null,
            currency: (string) ($data['currency'] ?? 'USD'),
            returnStock: isset($data['return_stock']) ? (bool) $data['return_stock'] : true,
            reason: (string) ($data['reason'] ?? 'supplier_return'),
            notes: isset($data['notes']) ? (string) $data['notes'] : null,
            items: array_values($items),
        );
    }
}
