<?php

declare(strict_types=1);

namespace Modules\Payments\DTOs;

final readonly class PaymentAllocationItemData
{
    public function __construct(
        public string $payableType,
        public string $payableId,
        public int $amountCents,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            payableType: (string) ($data['payable_type'] ?? 'invoice'),
            payableId: (string) ($data['payable_id'] ?? ''),
            amountCents: (int) ($data['amount_cents'] ?? 0),
        );
    }
}
