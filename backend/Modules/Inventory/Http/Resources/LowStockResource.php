<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Models\StockLevel;

/**
 * @mixin StockLevel
 */
final class LowStockResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $threshold = (int) $this->product->low_stock_threshold;
        $onHand = (int) $this->quantity_on_hand;
        $deficit = max(0, $threshold - $onHand);

        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product_name' => $this->product->name,
            'sku' => $this->product->sku,
            'barcode' => $this->product->barcode,
            'category_name' => $this->product->category?->name,
            'unit_of_measure' => $this->product->unitOfMeasure?->symbol,
            'warehouse_id' => $this->warehouse_id,
            'warehouse_name' => $this->warehouse->name,
            'quantity_on_hand' => $onHand,
            'quantity_reserved' => (int) $this->quantity_reserved,
            'low_stock_threshold' => $threshold,
            'deficit' => $deficit,
            'is_out_of_stock' => $onHand <= 0,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
