<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

use Modules\Inventory\Enums\TaxCategory;

final readonly class CreateProductData
{
    public function __construct(
        public string $name,
        public string $sku,
        public int $costPriceCents,
        public int $sellingPriceCents,
        public TaxCategory $taxCategory = TaxCategory::Standard,
        public int $lowStockThreshold = 0,
        public ?string $barcode = null,
        public ?string $categoryId = null,
        public ?string $unitOfMeasureId = null,
        public ?string $description = null,
        public bool $isActive = true,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'sku' => $this->sku,
            'cost_price_cents' => $this->costPriceCents,
            'selling_price_cents' => $this->sellingPriceCents,
            'tax_category' => $this->taxCategory->value,
            'low_stock_threshold' => $this->lowStockThreshold,
            'barcode' => $this->barcode,
            'category_id' => $this->categoryId,
            'unit_of_measure_id' => $this->unitOfMeasureId,
            'description' => $this->description,
            'is_active' => $this->isActive,
        ];
    }
}
