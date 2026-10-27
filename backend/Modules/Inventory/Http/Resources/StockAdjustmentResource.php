<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Models\StockAdjustment;

/**
 * @mixin StockAdjustment
 */
final class StockAdjustmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'adjustment_number' => $this->adjustment_number,
            'warehouse_id' => $this->warehouse_id,
            'reason' => $this->reason,
            'user_id' => $this->user_id,
            'status' => $this->status,
            'total_cost_impact_cents' => $this->total_cost_impact_cents,
            'warehouse' => new WarehouseResource($this->whenLoaded('warehouse')),
            'items' => StockAdjustmentItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
