<?php

declare(strict_types=1);

namespace Modules\Payments\DTOs;

use Modules\Payments\Enums\PaymentMethod;
use Modules\Payments\Enums\PaymentType;

final readonly class CreatePaymentData
{
    /**
     * @param  array<PaymentAllocationItemData>  $allocations
     */
    public function __construct(
        public PaymentType $type,
        public string $contactId,
        public int $amountCents,
        public PaymentMethod $paymentMethod,
        public string $paymentDate,
        public string $currency = 'USD',
        public ?string $referenceNumber = null,
        public ?string $notes = null,
        public array $allocations = [],
        public bool $postImmediately = false,
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
            type: PaymentType::from((string) ($data['type'] ?? 'inbound')),
            contactId: (string) ($data['contact_id'] ?? ''),
            amountCents: (int) ($data['amount_cents'] ?? 0),
            paymentMethod: PaymentMethod::from((string) ($data['payment_method'] ?? 'bank_transfer')),
            paymentDate: (string) ($data['payment_date'] ?? date('Y-m-d')),
            currency: strtoupper((string) ($data['currency'] ?? 'USD')),
            referenceNumber: isset($data['reference_number']) ? (string) $data['reference_number'] : null,
            notes: isset($data['notes']) ? (string) $data['notes'] : null,
            allocations: $allocations,
            postImmediately: (bool) ($data['post_immediately'] ?? false),
        );
    }
}
