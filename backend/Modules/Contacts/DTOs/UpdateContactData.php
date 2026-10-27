<?php

declare(strict_types=1);

namespace Modules\Contacts\DTOs;

use Modules\Contacts\Enums\ContactType;

final readonly class UpdateContactData
{
    /**
     * @param  array<string, mixed>|null  $billingAddress
     * @param  array<string, mixed>|null  $shippingAddress
     */
    public function __construct(
        public ?ContactType $type = null,
        public ?string $name = null,
        public ?string $code = null,
        public ?string $taxId = null,
        public ?string $email = null,
        public ?string $phone = null,
        public ?int $paymentTermsDays = null,
        public ?int $creditLimitCents = null,
        public ?int $openingBalanceCents = null,
        public ?bool $isActive = null,
        public ?string $notes = null,
        public ?array $billingAddress = null,
        public ?array $shippingAddress = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toFilteredArray(): array
    {
        $data = [];

        if ($this->type !== null) {
            $data['type'] = $this->type->value;
        }
        if ($this->name !== null) {
            $data['name'] = $this->name;
        }
        if ($this->code !== null) {
            $data['code'] = $this->code;
        }
        if ($this->taxId !== null) {
            $data['tax_id'] = $this->taxId;
        }
        if ($this->email !== null) {
            $data['email'] = $this->email;
        }
        if ($this->phone !== null) {
            $data['phone'] = $this->phone;
        }
        if ($this->paymentTermsDays !== null) {
            $data['payment_terms_days'] = $this->paymentTermsDays;
        }
        if ($this->creditLimitCents !== null) {
            $data['credit_limit_cents'] = $this->creditLimitCents;
        }
        if ($this->openingBalanceCents !== null) {
            $data['opening_balance_cents'] = $this->openingBalanceCents;
        }
        if ($this->isActive !== null) {
            $data['is_active'] = $this->isActive;
        }
        if ($this->notes !== null) {
            $data['notes'] = $this->notes;
        }
        if ($this->billingAddress !== null) {
            $data['billing_address'] = $this->billingAddress;
        }
        if ($this->shippingAddress !== null) {
            $data['shipping_address'] = $this->shippingAddress;
        }

        return $data;
    }
}
