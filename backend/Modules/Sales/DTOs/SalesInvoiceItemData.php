<?php

declare(strict_types=1);

namespace Modules\Sales\DTOs;

final readonly class SalesInvoiceItemData
{
    public function __construct(
        public string $productId,
        public int $quantity,
        public int $unitPriceCents,
        public int $taxRatePercent = 0,
        public ?string $taxCategory = null,
        public ?string $description = null,
        public ?string $notes = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            productId: (string) $data['product_id'],
            quantity: (int) $data['quantity'],
            unitPriceCents: (int) $data['unit_price_cents'],
            taxRatePercent: (int) ($data['tax_rate_percent'] ?? 0),
            taxCategory: isset($data['tax_category']) ? (string) $data['tax_category'] : null,
            description: isset($data['description']) ? (string) $data['description'] : null,
            notes: isset($data['notes']) ? (string) $data['notes'] : null,
        );
    }
}
