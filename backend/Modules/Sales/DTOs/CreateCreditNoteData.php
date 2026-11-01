<?php

declare(strict_types=1);

namespace Modules\Sales\DTOs;

final readonly class CreateCreditNoteData
{
    /**
     * @param  list<CreditNoteItemData>  $items
     */
    public function __construct(
        public string $customerId,
        public string $warehouseId,
        public string $issueDate,
        public ?string $salesInvoiceId = null,
        public string $currency = 'USD',
        public bool $restock = true,
        public string $reason = 'customer_return',
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
            static fn (array $item): CreditNoteItemData => CreditNoteItemData::fromArray($item),
            $rawItems
        );

        return new self(
            customerId: (string) $data['customer_id'],
            warehouseId: (string) $data['warehouse_id'],
            issueDate: (string) $data['issue_date'],
            salesInvoiceId: isset($data['sales_invoice_id']) && $data['sales_invoice_id'] !== '' ? (string) $data['sales_invoice_id'] : null,
            currency: (string) ($data['currency'] ?? 'USD'),
            restock: isset($data['restock']) ? (bool) $data['restock'] : true,
            reason: (string) ($data['reason'] ?? 'customer_return'),
            notes: isset($data['notes']) ? (string) $data['notes'] : null,
            items: array_values($items),
        );
    }
}
