<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\DTOs\RecordStockMovementData;
use Modules\Inventory\Enums\StockMovementType;

final class RecordStockMovementRequest extends FormRequest
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
            'product_id' => ['required', 'string', Rule::exists('tenant.products', 'id')],
            'type' => ['required', 'string', Rule::enum(StockMovementType::class)],
            'quantity' => ['required', 'integer', 'min:1'],
            'unit_cost_cents' => ['nullable', 'integer', 'min:0'],
            'source_type' => ['nullable', 'string', 'max:100'],
            'source_id' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function toDto(): RecordStockMovementData
    {
        $validated = $this->validated();

        /** @var string|null $userId */
        $userId = $this->user()?->getAuthIdentifier();

        return new RecordStockMovementData(
            warehouseId: (string) $validated['warehouse_id'],
            productId: (string) $validated['product_id'],
            type: StockMovementType::from((string) $validated['type']),
            quantity: (int) $validated['quantity'],
            unitCostCents: isset($validated['unit_cost_cents']) ? (int) $validated['unit_cost_cents'] : 0,
            sourceType: isset($validated['source_type']) ? (string) $validated['source_type'] : null,
            sourceId: isset($validated['source_id']) ? (string) $validated['source_id'] : null,
            userId: $userId,
            notes: isset($validated['notes']) ? (string) $validated['notes'] : null,
        );
    }
}
