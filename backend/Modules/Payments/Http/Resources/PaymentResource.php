<?php

declare(strict_types=1);

namespace Modules\Payments\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Payments\Models\Payment;

/**
 * @mixin Payment
 */
final class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payment_number' => $this->payment_number,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'contact_id' => $this->contact_id,
            'amount_cents' => $this->amount_cents,
            'allocated_amount_cents' => $this->allocated_amount_cents,
            'unallocated_amount_cents' => $this->unallocated_amount_cents,
            'currency' => $this->currency,
            'payment_method' => $this->payment_method->value,
            'payment_method_label' => $this->payment_method->label(),
            'payment_date' => $this->payment_date,
            'reference_number' => $this->reference_number,
            'notes' => $this->notes,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'created_by_user_id' => $this->created_by_user_id,
            'posted_by_user_id' => $this->posted_by_user_id,
            'posted_at' => $this->posted_at?->toIso8601String(),
            'voided_at' => $this->voided_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'allocations' => PaymentAllocationResource::collection($this->whenLoaded('allocations')),
        ];
    }
}
