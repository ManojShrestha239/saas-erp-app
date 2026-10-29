<?php

declare(strict_types=1);

namespace Modules\Purchasing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Auth\Models\TenantUser;
use Modules\Purchasing\DTOs\CreatePurchaseOrderData;
use Modules\Purchasing\DTOs\PurchaseOrderItemData;

final class StorePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user instanceof TenantUser) {
            return false;
        }

        return $user->hasAnyRole(['owner', 'admin', 'accountant', 'warehouse', 'warehouse_manager'])
            || $user->can('purchases.manage')
            || $user->can('purchases.po.draft');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'supplier_id' => [
                'required',
                'string',
                Rule::exists('tenant.contacts', 'id')->where(
                    fn ($query) => $query->whereIn('type', ['supplier', 'both'])
                ),
            ],
            'warehouse_id' => ['required', 'string', Rule::exists('tenant.warehouses', 'id')],
            'order_date' => ['required', 'date'],
            'expected_delivery_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'currency' => ['nullable', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'string', Rule::exists('tenant.products', 'id')],
            'items.*.quantity_ordered' => ['required', 'integer', 'min:1'],
            'items.*.unit_cost_cents' => ['required', 'integer', 'min:0'],
            'items.*.tax_rate_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'items.*.tax_category' => ['nullable', 'string', 'max:32'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function toDto(): CreatePurchaseOrderData
    {
        $validated = $this->validated();

        /** @var string|null $userId */
        $userId = $this->user()?->getAuthIdentifier();

        /** @var list<array{product_id: string, quantity_ordered: int, unit_cost_cents: int, tax_rate_percent?: ?int, tax_category?: ?string, notes?: ?string}> $rawItems */
        $rawItems = array_values((array) $validated['items']);

        $items = array_map(
            fn (array $item): PurchaseOrderItemData => new PurchaseOrderItemData(
                productId: (string) $item['product_id'],
                quantityOrdered: (int) $item['quantity_ordered'],
                unitCostCents: (int) $item['unit_cost_cents'],
                taxRatePercent: isset($item['tax_rate_percent']) ? (int) $item['tax_rate_percent'] : 0,
                taxCategory: $item['tax_category'] ?? null,
                notes: $item['notes'] ?? null,
            ),
            $rawItems
        );

        return new CreatePurchaseOrderData(
            supplierId: (string) $validated['supplier_id'],
            warehouseId: (string) $validated['warehouse_id'],
            orderDate: (string) $validated['order_date'],
            expectedDeliveryDate: isset($validated['expected_delivery_date']) ? (string) $validated['expected_delivery_date'] : null,
            currency: isset($validated['currency']) ? (string) $validated['currency'] : 'USD',
            notes: isset($validated['notes']) ? (string) $validated['notes'] : null,
            userId: $userId,
            items: $items,
        );
    }
}
