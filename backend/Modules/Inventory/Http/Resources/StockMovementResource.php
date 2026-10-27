<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Models\StockMovement;

/**
 * @mixin StockMovement
 */
final class StockMovementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'warehouse_id' => $this->warehouse_id,
            'product_id' => $this->product_id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'quantity' => $this->quantity,
            'unit_cost_cents' => $this->unit_cost_cents,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'user_id' => $this->user_id,
            'notes' => $this->notes,
            'warehouse' => new WarehouseResource($this->whenLoaded('warehouse')),
            'product' => new ProductResource($this->whenLoaded('product')),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
