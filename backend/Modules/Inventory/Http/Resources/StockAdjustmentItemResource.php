<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Models\StockAdjustmentItem;

/**
 * @mixin StockAdjustmentItem
 */
final class StockAdjustmentItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'stock_adjustment_id' => $this->stock_adjustment_id,
            'product_id' => $this->product_id,
            'quantity_before' => $this->quantity_before,
            'quantity_delta' => $this->quantity_delta,
            'quantity_after' => $this->quantity_after,
            'unit_cost_cents' => $this->unit_cost_cents,
            'line_cost_impact_cents' => $this->quantity_delta * $this->unit_cost_cents,
            'product' => new ProductResource($this->whenLoaded('product')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
