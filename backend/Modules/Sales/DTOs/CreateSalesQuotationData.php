<?php

declare(strict_types=1);

namespace Modules\Sales\DTOs;

final readonly class CreateSalesQuotationData
{
    /**
     * @param  list<SalesQuotationItemData>  $items
     */
    public function __construct(
        public string $customerId,
        public string $issueDate,
        public ?string $expiryDate = null,
        public string $currency = 'USD',
        public ?string $warehouseId = null,
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
            static fn (array $item): SalesQuotationItemData => SalesQuotationItemData::fromArray($item),
            $rawItems
        );

        return new self(
            customerId: (string) $data['customer_id'],
            issueDate: (string) $data['issue_date'],
            expiryDate: isset($data['expiry_date']) ? (string) $data['expiry_date'] : null,
            currency: (string) ($data['currency'] ?? 'USD'),
            warehouseId: isset($data['warehouse_id']) ? (string) $data['warehouse_id'] : null,
            notes: isset($data['notes']) ? (string) $data['notes'] : null,
            items: array_values($items),
        );
    }
}
