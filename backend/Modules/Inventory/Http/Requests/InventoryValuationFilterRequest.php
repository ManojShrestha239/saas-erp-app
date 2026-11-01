<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class InventoryValuationFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        return $user->can('products.view');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'string'],
            'warehouse_id' => ['nullable', 'string'],
            'in_stock_only' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort_field' => ['nullable', 'string', 'in:sku,name,total_quantity_on_hand,moving_average_cost_cents,selling_price_cents,total_valuation_cents'],
            'sort_direction' => ['nullable', 'string', 'in:asc,desc'],
        ];
    }
}
