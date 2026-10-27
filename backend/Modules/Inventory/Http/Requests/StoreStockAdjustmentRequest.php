<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\DTOs\CreateStockAdjustmentData;
use Modules\Inventory\DTOs\StockAdjustmentItemData;

final class StoreStockAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        return $user->can('stock.adjust') || $user->can('products.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'warehouse_id' => ['required', 'string', Rule::exists('tenant.warehouses', 'id')],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'string', Rule::exists('tenant.products', 'id')],
            'items.*.quantity_delta' => ['required', 'integer', 'not_in:0'],
            'items.*.unit_cost_cents' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function toDto(): CreateStockAdjustmentData
    {
        $validated = $this->validated();

        /** @var string|null $userId */
        $userId = $this->user()?->getAuthIdentifier();

        /** @var list<array{product_id: string, quantity_delta: int, unit_cost_cents?: ?int}> $rawItems */
        $rawItems = array_values((array) $validated['items']);

        $items = array_map(
            fn (array $item): StockAdjustmentItemData => new StockAdjustmentItemData(
                productId: (string) $item['product_id'],
                quantityDelta: (int) $item['quantity_delta'],
                unitCostCents: isset($item['unit_cost_cents'])
                    ? (int) $item['unit_cost_cents']
                    : null,
            ),
            $rawItems
        );

        return new CreateStockAdjustmentData(
            warehouseId: (string) $validated['warehouse_id'],
            reason: (string) $validated['reason'],
            items: $items,
            userId: $userId,
        );
    }
}
