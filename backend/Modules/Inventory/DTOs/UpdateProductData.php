<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

use Modules\Inventory\Enums\TaxCategory;

final readonly class UpdateProductData
{
    public function __construct(
        public ?string $name = null,
        public ?string $sku = null,
        public ?int $costPriceCents = null,
        public ?int $sellingPriceCents = null,
        public ?TaxCategory $taxCategory = null,
        public ?int $lowStockThreshold = null,
        public ?string $barcode = null,
        public ?string $categoryId = null,
        public ?string $unitOfMeasureId = null,
        public ?string $description = null,
        public ?bool $isActive = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toFilteredArray(): array
    {
        $data = [];

        if ($this->name !== null) {
            $data['name'] = $this->name;
        }
        if ($this->sku !== null) {
            $data['sku'] = $this->sku;
        }
        if ($this->costPriceCents !== null) {
            $data['cost_price_cents'] = $this->costPriceCents;
        }
        if ($this->sellingPriceCents !== null) {
            $data['selling_price_cents'] = $this->sellingPriceCents;
        }
        if ($this->taxCategory !== null) {
            $data['tax_category'] = $this->taxCategory->value;
        }
        if ($this->lowStockThreshold !== null) {
            $data['low_stock_threshold'] = $this->lowStockThreshold;
        }
        if ($this->barcode !== null) {
            $data['barcode'] = $this->barcode;
        }
        if ($this->categoryId !== null) {
            $data['category_id'] = $this->categoryId;
        }
        if ($this->unitOfMeasureId !== null) {
            $data['unit_of_measure_id'] = $this->unitOfMeasureId;
        }
        if ($this->description !== null) {
            $data['description'] = $this->description;
        }
        if ($this->isActive !== null) {
            $data['is_active'] = $this->isActive;
        }

        return $data;
    }
}
