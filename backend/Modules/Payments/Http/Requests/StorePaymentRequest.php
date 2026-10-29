<?php

declare(strict_types=1);

namespace Modules\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Auth\Enums\TenantPermission;
use Modules\Payments\DTOs\CreatePaymentData;
use Modules\Payments\DTOs\PaymentAllocationItemData;
use Modules\Payments\Enums\PaymentMethod;
use Modules\Payments\Enums\PaymentType;

final class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        $type = (string) $this->input('type');
        if ($type === 'inbound') {
            return $user->can('payments.manage')
                || $user->can(TenantPermission::PaymentsCustomerReceipts->value)
                || $user->can('sales.manage');
        }

        if ($type === 'outbound') {
            return $user->can('payments.manage')
                || $user->can(TenantPermission::PaymentsSupplierBills->value)
                || $user->can('purchases.manage');
        }

        return $user->can('payments.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['inbound', 'outbound'])],
            'contact_id' => ['required', 'string'],
            'amount_cents' => ['required', 'integer', 'min:1'],
            'payment_method' => ['required', Rule::in(['cash', 'bank_transfer', 'cheque', 'card', 'other'])],
            'payment_date' => ['required', 'date'],
            'currency' => ['nullable', 'string', 'size:3'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'allocations' => ['nullable', 'array'],
            'allocations.*.payable_type' => ['required_with:allocations', Rule::in(['invoice', 'bill'])],
            'allocations.*.payable_id' => ['required_with:allocations', 'string'],
            'allocations.*.amount_cents' => ['required_with:allocations', 'integer', 'min:1'],
            'post_immediately' => ['nullable', 'boolean'],
        ];
    }

    public function toDto(): CreatePaymentData
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();

        $allocations = [];
        if (! empty($data['allocations']) && is_array($data['allocations'])) {
            foreach ($data['allocations'] as $item) {
                if (is_array($item)) {
                    $allocations[] = new PaymentAllocationItemData(
                        payableType: (string) ($item['payable_type'] ?? 'invoice'),
                        payableId: (string) ($item['payable_id'] ?? ''),
                        amountCents: (int) ($item['amount_cents'] ?? 0),
                    );
                }
            }
        }

        return new CreatePaymentData(
            type: PaymentType::from((string) $data['type']),
            contactId: (string) $data['contact_id'],
            amountCents: (int) $data['amount_cents'],
            paymentMethod: PaymentMethod::from((string) $data['payment_method']),
            paymentDate: (string) $data['payment_date'],
            currency: strtoupper((string) ($data['currency'] ?? 'USD')),
            referenceNumber: isset($data['reference_number']) ? (string) $data['reference_number'] : null,
            notes: isset($data['notes']) ? (string) $data['notes'] : null,
            allocations: $allocations,
            postImmediately: (bool) ($data['post_immediately'] ?? false),
        );
    }
}
