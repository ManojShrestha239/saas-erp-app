<?php

declare(strict_types=1);

namespace Modules\Contacts\DTOs;

use Modules\Contacts\Enums\ContactType;

final readonly class CreateContactData
{
    /**
     * @param  array<string, mixed>|null  $billingAddress
     * @param  array<string, mixed>|null  $shippingAddress
     */
    public function __construct(
        public ContactType $type,
        public string $name,
        public ?string $code = null,
        public ?string $taxId = null,
        public ?string $email = null,
        public ?string $phone = null,
        public int $paymentTermsDays = 0,
        public int $creditLimitCents = 0,
        public int $openingBalanceCents = 0,
        public bool $isActive = true,
        public ?string $notes = null,
        public ?array $billingAddress = null,
        public ?array $shippingAddress = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'name' => $this->name,
            'code' => $this->code,
            'tax_id' => $this->taxId,
            'email' => $this->email,
            'phone' => $this->phone,
            'payment_terms_days' => $this->paymentTermsDays,
            'credit_limit_cents' => $this->creditLimitCents,
            'opening_balance_cents' => $this->openingBalanceCents,
            'is_active' => $this->isActive,
            'notes' => $this->notes,
            'billing_address' => $this->billingAddress,
            'shipping_address' => $this->shippingAddress,
        ];
    }
}
