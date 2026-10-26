<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\DTOs\CreateProductData;
use Modules\Inventory\Enums\TaxCategory;

final class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('products.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['required', 'string', 'max:50', Rule::unique('tenant.products', 'sku')],
            'barcode' => ['nullable', 'string', 'max:50'],
            'category_id' => ['nullable', 'string', Rule::exists('tenant.categories', 'id')],
            'unit_of_measure_id' => ['nullable', 'string', Rule::exists('tenant.units_of_measure', 'id')],
            'cost_price_cents' => ['required', 'integer', 'min:0'],
            'selling_price_cents' => ['required', 'integer', 'min:0'],
            'tax_category' => ['required', 'string', Rule::enum(TaxCategory::class)],
            'low_stock_threshold' => ['nullable', 'integer', 'min:0'],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function toDto(): CreateProductData
    {
        $validated = $this->validated();

        return new CreateProductData(
            name: (string) $validated['name'],
            sku: (string) $validated['sku'],
            costPriceCents: (int) $validated['cost_price_cents'],
            sellingPriceCents: (int) $validated['selling_price_cents'],
            taxCategory: TaxCategory::from((string) $validated['tax_category']),
            lowStockThreshold: isset($validated['low_stock_threshold']) ? (int) $validated['low_stock_threshold'] : 0,
            barcode: ! empty($validated['barcode']) ? (string) $validated['barcode'] : null,
            categoryId: ! empty($validated['category_id']) ? (string) $validated['category_id'] : null,
            unitOfMeasureId: ! empty($validated['unit_of_measure_id']) ? (string) $validated['unit_of_measure_id'] : null,
            description: array_key_exists('description', $validated) ? ($validated['description'] !== null ? (string) $validated['description'] : null) : null,
            isActive: isset($validated['is_active']) ? (bool) $validated['is_active'] : true,
        );
    }
}
