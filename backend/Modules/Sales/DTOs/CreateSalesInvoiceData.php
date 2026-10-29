<?php

declare(strict_types=1);

namespace Modules\Sales\DTOs;

final readonly class CreateSalesInvoiceData
{
    /**
     * @param  list<SalesInvoiceItemData>  $items
     */
    public function __construct(
        public string $customerId,
        public string $issueDate,
        public string $dueDate,
        public string $currency = 'USD',
        public ?string $quotationId = null,
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
            static fn (array $item): SalesInvoiceItemData => SalesInvoiceItemData::fromArray($item),
            $rawItems
        );

        return new self(
            customerId: (string) $data['customer_id'],
            issueDate: (string) $data['issue_date'],
            dueDate: (string) $data['due_date'],
            currency: (string) ($data['currency'] ?? 'USD'),
            quotationId: isset($data['sales_quotation_id']) ? (string) $data['sales_quotation_id'] : (isset($data['quotation_id']) ? (string) $data['quotation_id'] : null),
            notes: isset($data['notes']) ? (string) $data['notes'] : null,
            items: array_values($items),
        );
    }
}
