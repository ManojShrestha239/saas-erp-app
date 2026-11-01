<?php

declare(strict_types=1);

namespace Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Sales\Models\CreditNote;

/**
 * @mixin CreditNote
 */
final class CreditNoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'credit_note_number' => $this->credit_note_number,
            'customer_id' => $this->customer_id,
            'sales_invoice_id' => $this->sales_invoice_id,
            'warehouse_id' => $this->warehouse_id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'issue_date' => $this->issue_date->toDateString(),
            'currency' => $this->currency,
            'subtotal_cents' => $this->subtotal_cents,
            'tax_cents' => $this->tax_cents,
            'total_cents' => $this->total_cents,
            'restock' => $this->restock,
            'reason' => $this->reason,
            'notes' => $this->notes,
            'created_by_user_id' => $this->created_by_user_id,
            'posted_at' => $this->posted_at?->toIso8601String(),
            'voided_at' => $this->voided_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'items' => CreditNoteItemResource::collection($this->whenLoaded('items')),
            'invoice' => new SalesInvoiceResource($this->whenLoaded('invoice')),
        ];
    }
}
