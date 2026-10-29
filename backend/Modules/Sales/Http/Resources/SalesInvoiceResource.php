<?php

declare(strict_types=1);

namespace Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Sales\Models\SalesInvoice;

/**
 * @mixin SalesInvoice
 */
final class SalesInvoiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_number' => $this->invoice_number,
            'sales_quotation_id' => $this->sales_quotation_id,
            'customer_id' => $this->customer_id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'issue_date' => $this->issue_date->toDateString(),
            'due_date' => $this->due_date->toDateString(),
            'currency' => $this->currency,
            'subtotal_cents' => $this->subtotal_cents,
            'tax_cents' => $this->tax_cents,
            'total_cents' => $this->total_cents,
            'amount_paid_cents' => $this->amount_paid_cents,
            'amount_due_cents' => $this->amount_due_cents,
            'notes' => $this->notes,
            'created_by_user_id' => $this->created_by_user_id,
            'posted_by_user_id' => $this->posted_by_user_id,
            'posted_at' => $this->posted_at?->toIso8601String(),
            'voided_at' => $this->voided_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'items' => SalesInvoiceItemResource::collection($this->whenLoaded('items')),
            'quotation' => new SalesQuotationResource($this->whenLoaded('quotation')),
        ];
    }
}
