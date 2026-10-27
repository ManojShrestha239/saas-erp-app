<?php

declare(strict_types=1);

namespace Modules\Contacts\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Contacts\DTOs\CreateContactData;
use Modules\Contacts\Enums\ContactType;

final class StoreContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        $type = (string) $this->input('type');

        if ($type === ContactType::Customer->value) {
            return $user->can('customers.manage') || $user->can('sales.manage');
        }

        if ($type === ContactType::Supplier->value) {
            return $user->can('suppliers.manage') || $user->can('purchases.manage');
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
        return [
            'type' => ['required', 'string', Rule::enum(ContactType::class)],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50', Rule::unique('tenant.contacts', 'code')],
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

    public function toDto(): CreateContactData
    {
        $validated = $this->validated();

        /** @var array<string, mixed>|null $billingAddress */
        $billingAddress = isset($validated['billing_address']) && is_array($validated['billing_address'])
            ? $validated['billing_address']
            : null;

        /** @var array<string, mixed>|null $shippingAddress */
        $shippingAddress = isset($validated['shipping_address']) && is_array($validated['shipping_address'])
            ? $validated['shipping_address']
            : null;

        return new CreateContactData(
            type: ContactType::from((string) $validated['type']),
            name: (string) $validated['name'],
            code: ! empty($validated['code']) ? (string) $validated['code'] : null,
            taxId: ! empty($validated['tax_id']) ? (string) $validated['tax_id'] : null,
            email: ! empty($validated['email']) ? (string) $validated['email'] : null,
            phone: ! empty($validated['phone']) ? (string) $validated['phone'] : null,
            paymentTermsDays: isset($validated['payment_terms_days']) ? (int) $validated['payment_terms_days'] : 0,
            creditLimitCents: isset($validated['credit_limit_cents']) ? (int) $validated['credit_limit_cents'] : 0,
            openingBalanceCents: isset($validated['opening_balance_cents']) ? (int) $validated['opening_balance_cents'] : 0,
            isActive: isset($validated['is_active']) ? (bool) $validated['is_active'] : true,
            notes: isset($validated['notes']) ? (string) $validated['notes'] : null,
            billingAddress: $billingAddress,
            shippingAddress: $shippingAddress,
        );
    }
}
