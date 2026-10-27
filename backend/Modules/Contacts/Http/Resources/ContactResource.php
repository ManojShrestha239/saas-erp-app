<?php

declare(strict_types=1);

namespace Modules\Contacts\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Contacts\Models\Contact;

/**
 * @mixin Contact
 */
final class ContactResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'code' => $this->code,
            'name' => $this->name,
            'tax_id' => $this->tax_id,
            'email' => $this->email,
            'phone' => $this->phone,
            'payment_terms_days' => $this->payment_terms_days,
            'credit_limit_cents' => $this->credit_limit_cents,
            'opening_balance_cents' => $this->opening_balance_cents,
            'is_active' => $this->is_active,
            'notes' => $this->notes,
            'billing_address' => $this->billing_address,
            'shipping_address' => $this->shipping_address,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
