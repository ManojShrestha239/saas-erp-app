<?php

declare(strict_types=1);

namespace Modules\Purchasing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Purchasing\DTOs\CreateSupplierBillData;
use Modules\Purchasing\DTOs\SupplierBillItemData;

final class StoreSupplierBillRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        return $user->can('purchases.manage');
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
            'bill_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:bill_date'],
            'supplier_invoice_number' => ['nullable', 'string', 'max:100'],
            'purchase_order_id' => ['nullable', 'string', Rule::exists('tenant.purchase_orders', 'id')],
            'currency' => ['nullable', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_cost_cents' => ['required', 'integer', 'min:0'],
            'items.*.tax_rate_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'items.*.purchase_order_item_id' => ['nullable', 'string', Rule::exists('tenant.purchase_order_items', 'id')],
            'items.*.product_id' => ['nullable', 'string', Rule::exists('tenant.products', 'id')],
        ];
    }

    public function toDto(): CreateSupplierBillData
    {
        $validated = $this->validated();

        /** @var string|null $userId */
        $userId = $this->user()?->getAuthIdentifier();

        /** @var list<array{description: string, quantity: int, unit_cost_cents: int, tax_rate_percent?: ?int, purchase_order_item_id?: ?string, product_id?: ?string}> $rawItems */
        $rawItems = array_values((array) $validated['items']);

        $items = array_map(
            fn (array $item): SupplierBillItemData => new SupplierBillItemData(
                description: (string) $item['description'],
                quantity: (int) $item['quantity'],
                unitCostCents: (int) $item['unit_cost_cents'],
                taxRatePercent: isset($item['tax_rate_percent']) ? (int) $item['tax_rate_percent'] : 0,
                purchaseOrderItemId: $item['purchase_order_item_id'] ?? null,
                productId: $item['product_id'] ?? null,
            ),
            $rawItems
        );

        return new CreateSupplierBillData(
            supplierId: (string) $validated['supplier_id'],
            billDate: (string) $validated['bill_date'],
            dueDate: (string) $validated['due_date'],
            supplierInvoiceNumber: isset($validated['supplier_invoice_number']) ? (string) $validated['supplier_invoice_number'] : null,
            purchaseOrderId: isset($validated['purchase_order_id']) ? (string) $validated['purchase_order_id'] : null,
            currency: isset($validated['currency']) ? (string) $validated['currency'] : 'USD',
            notes: isset($validated['notes']) ? (string) $validated['notes'] : null,
            userId: $userId,
            items: $items,
        );
    }
}
