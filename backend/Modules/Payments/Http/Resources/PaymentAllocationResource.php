<?php

declare(strict_types=1);

namespace Modules\Payments\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Payments\Models\PaymentAllocation;

/**
 * @mixin PaymentAllocation
 */
final class PaymentAllocationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payment_id' => $this->payment_id,
            'payable_type' => $this->payable_type->value,
            'payable_type_label' => $this->payable_type->label(),
            'payable_id' => $this->payable_id,
            'amount_cents' => $this->amount_cents,
            'allocated_at' => $this->allocated_at?->toIso8601String(),
            'allocated_by_user_id' => $this->allocated_by_user_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
