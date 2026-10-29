<?php

declare(strict_types=1);

namespace Modules\Purchasing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Purchasing\Models\PurchaseOrder;

/**
 * @mixin PurchaseOrder
 */
final class PurchaseOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'supplier_id' => $this->supplier_id,
            'warehouse_id' => $this->warehouse_id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'order_date' => $this->order_date->toDateString(),
            'expected_delivery_date' => $this->expected_delivery_date?->toDateString(),
            'currency' => $this->currency,
            'subtotal_cents' => $this->subtotal_cents,
            'tax_cents' => $this->tax_cents,
            'total_cents' => $this->total_cents,
            'notes' => $this->notes,
            'created_by_user_id' => $this->created_by_user_id,
            'approved_by_user_id' => $this->approved_by_user_id,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'items' => PurchaseOrderItemResource::collection($this->whenLoaded('items')),
            'receipts' => GoodsReceiptResource::collection($this->whenLoaded('goodsReceipts')),
        ];
    }
}
