<?php

declare(strict_types=1);

namespace Modules\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Auth\Enums\TenantPermission;
use Modules\Payments\DTOs\AllocatePaymentData;
use Modules\Payments\DTOs\PaymentAllocationItemData;

final class AllocatePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        return $user->can('payments.manage')
            || $user->can(TenantPermission::PaymentsCustomerReceipts->value)
            || $user->can(TenantPermission::PaymentsSupplierBills->value)
            || $user->can('sales.manage')
            || $user->can('purchases.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*.payable_type' => ['required', Rule::in(['invoice', 'bill'])],
            'allocations.*.payable_id' => ['required', 'string'],
            'allocations.*.amount_cents' => ['required', 'integer', 'min:1'],
        ];
    }

    public function toDto(): AllocatePaymentData
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

        return new AllocatePaymentData(
            allocations: $allocations,
        );
    }
}
