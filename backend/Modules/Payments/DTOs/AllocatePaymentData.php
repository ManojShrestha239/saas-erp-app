<?php

declare(strict_types=1);

namespace Modules\Payments\DTOs;

final readonly class AllocatePaymentData
{
    /**
     * @param  array<PaymentAllocationItemData>  $allocations
     */
    public function __construct(
        public array $allocations,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $allocations = [];
        if (! empty($data['allocations']) && is_array($data['allocations'])) {
            foreach ($data['allocations'] as $alloc) {
                if (is_array($alloc)) {
                    $allocations[] = PaymentAllocationItemData::fromArray($alloc);
                }
            }
        }

        return new self(
            allocations: $allocations,
        );
    }
}
