<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\DTOs\UpdateProductData;
use Modules\Inventory\Enums\TaxCategory;

final class UpdateProductRequest extends FormRequest
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
        /** @var string|null $productId */
        $productId = $this->route('product') ?? $this->route('id');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'sku' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('tenant.products', 'sku')->ignore($productId),
            ],
            'barcode' => ['nullable', 'string', 'max:50'],
            'category_id' => ['nullable', 'string', Rule::exists('tenant.categories', 'id')],
            'unit_of_measure_id' => ['nullable', 'string', Rule::exists('tenant.units_of_measure', 'id')],
            'cost_price_cents' => ['sometimes', 'required', 'integer', 'min:0'],
            'selling_price_cents' => ['sometimes', 'required', 'integer', 'min:0'],
            'tax_category' => ['sometimes', 'required', 'string', Rule::enum(TaxCategory::class)],
            'low_stock_threshold' => ['nullable', 'integer', 'min:0'],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function toDto(): UpdateProductData
    {
        $validated = $this->validated();

        return new UpdateProductData(
            name: isset($validated['name']) ? (string) $validated['name'] : null,
            sku: isset($validated['sku']) ? (string) $validated['sku'] : null,
            costPriceCents: isset($validated['cost_price_cents']) ? (int) $validated['cost_price_cents'] : null,
            sellingPriceCents: isset($validated['selling_price_cents']) ? (int) $validated['selling_price_cents'] : null,
            taxCategory: isset($validated['tax_category']) ? TaxCategory::from((string) $validated['tax_category']) : null,
            lowStockThreshold: isset($validated['low_stock_threshold']) ? (int) $validated['low_stock_threshold'] : null,
            barcode: array_key_exists('barcode', $validated) ? ($validated['barcode'] !== null ? (string) $validated['barcode'] : null) : null,
            categoryId: array_key_exists('category_id', $validated) ? ($validated['category_id'] !== null ? (string) $validated['category_id'] : null) : null,
            unitOfMeasureId: array_key_exists('unit_of_measure_id', $validated) ? ($validated['unit_of_measure_id'] !== null ? (string) $validated['unit_of_measure_id'] : null) : null,
            description: array_key_exists('description', $validated) ? ($validated['description'] !== null ? (string) $validated['description'] : null) : null,
            isActive: isset($validated['is_active']) ? (bool) $validated['is_active'] : null,
        );
    }
}
