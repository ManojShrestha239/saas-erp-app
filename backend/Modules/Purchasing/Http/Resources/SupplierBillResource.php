<?php

declare(strict_types=1);

namespace Modules\Purchasing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Purchasing\Models\SupplierBill;

/**
 * @mixin SupplierBill
 */
final class SupplierBillResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bill_number' => $this->bill_number,
            'supplier_invoice_number' => $this->supplier_invoice_number,
            'supplier_id' => $this->supplier_id,
            'purchase_order_id' => $this->purchase_order_id,
            'bill_date' => $this->bill_date->toDateString(),
            'due_date' => $this->due_date->toDateString(),
            'currency' => $this->currency,
            'subtotal_cents' => $this->subtotal_cents,
            'tax_cents' => $this->tax_cents,
            'total_cents' => $this->total_cents,
            'amount_due_cents' => $this->amount_due_cents,
            'amount_paid_cents' => $this->amount_paid_cents,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'posted_at' => $this->posted_at?->toIso8601String(),
            'posted_by_user_id' => $this->posted_by_user_id,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'items' => SupplierBillItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
