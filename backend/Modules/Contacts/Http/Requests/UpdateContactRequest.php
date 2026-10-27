<?php

declare(strict_types=1);

namespace Modules\Contacts\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Contacts\DTOs\UpdateContactData;
use Modules\Contacts\Enums\ContactType;

final class UpdateContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        return $user->can('customers.manage')
            || $user->can('suppliers.manage')
            || $user->can('sales.manage')
            || $user->can('purchases.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var string|null $contactId */
        $contactId = $this->route('contact') ?? $this->route('id');

        return [
            'type' => ['sometimes', 'required', 'string', Rule::enum(ContactType::class)],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('tenant.contacts', 'code')->ignore($contactId),
            ],
            'tax_id' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0'],
            'credit_limit_cents' => ['nullable', 'integer', 'min:0'],
            'opening_balance_cents' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
            'billing_address' => ['nullable', 'array'],
            'shipping_address' => ['nullable', 'array'],
        ];
    }

    public function toDto(): UpdateContactData
    {
        $validated = $this->validated();

        /** @var array<string, mixed>|null $billingAddress */
        $billingAddress = array_key_exists('billing_address', $validated)
            ? (is_array($validated['billing_address']) ? $validated['billing_address'] : null)
            : null;

        /** @var array<string, mixed>|null $shippingAddress */
        $shippingAddress = array_key_exists('shipping_address', $validated)
            ? (is_array($validated['shipping_address']) ? $validated['shipping_address'] : null)
            : null;

        return new UpdateContactData(
            type: isset($validated['type']) ? ContactType::from((string) $validated['type']) : null,
            name: isset($validated['name']) ? (string) $validated['name'] : null,
            code: isset($validated['code']) ? (string) $validated['code'] : null,
            taxId: array_key_exists('tax_id', $validated) ? ($validated['tax_id'] !== null ? (string) $validated['tax_id'] : null) : null,
            email: array_key_exists('email', $validated) ? ($validated['email'] !== null ? (string) $validated['email'] : null) : null,
            phone: array_key_exists('phone', $validated) ? ($validated['phone'] !== null ? (string) $validated['phone'] : null) : null,
            paymentTermsDays: isset($validated['payment_terms_days']) ? (int) $validated['payment_terms_days'] : null,
            creditLimitCents: isset($validated['credit_limit_cents']) ? (int) $validated['credit_limit_cents'] : null,
            openingBalanceCents: isset($validated['opening_balance_cents']) ? (int) $validated['opening_balance_cents'] : null,
            isActive: isset($validated['is_active']) ? (bool) $validated['is_active'] : null,
            notes: array_key_exists('notes', $validated) ? ($validated['notes'] !== null ? (string) $validated['notes'] : null) : null,
            billingAddress: $billingAddress,
            shippingAddress: $shippingAddress,
        );
    }
}
