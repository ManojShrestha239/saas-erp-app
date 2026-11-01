<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

final readonly class ProductValuationData
{
    /**
     * @param  list<array{warehouse_id: string, warehouse_name: string, quantity_on_hand: int, valuation_cents: int}>  $warehouseBreakdown
     */
    public function __construct(
        public string $productId,
        public string $sku,
        public string $name,
        public ?string $categoryName,
        public ?string $unitOfMeasureName,
        public int $totalQuantityOnHand,
        public int $movingAverageCostCents,
        public int $sellingPriceCents,
        public int $totalValuationCents,
        public ?int $marginPercent,
        public bool $isActive,
        public array $warehouseBreakdown = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'product_id' => $this->productId,
            'sku' => $this->sku,
            'name' => $this->name,
            'category_name' => $this->categoryName,
            'unit_of_measure_name' => $this->unitOfMeasureName,
            'total_quantity_on_hand' => $this->totalQuantityOnHand,
            'moving_average_cost_cents' => $this->movingAverageCostCents,
            'selling_price_cents' => $this->sellingPriceCents,
            'total_valuation_cents' => $this->totalValuationCents,
            'margin_percent' => $this->marginPercent,
            'is_active' => $this->isActive,
            'warehouse_breakdown' => $this->warehouseBreakdown,
        ];
    }
}
