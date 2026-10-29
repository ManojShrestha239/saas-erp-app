<?php

declare(strict_types=1);

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sales\DTOs\CreateSalesInvoiceData;
use Modules\Sales\DTOs\SalesInvoiceItemData;

final class StoreSalesInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        return $user->can('sales.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_id' => [
                'required',
                'string',
                Rule::exists('tenant.contacts', 'id')->where(
                    fn ($query) => $query->whereIn('type', ['customer', 'both'])
                ),
            ],
            'sales_quotation_id' => ['nullable', 'string', Rule::exists('tenant.sales_quotations', 'id')],
            'warehouse_id' => ['nullable', 'string', Rule::exists('tenant.warehouses', 'id')],
            'issue_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:issue_date'],
            'currency' => ['nullable', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'string', Rule::exists('tenant.products', 'id')],
            'items.*.description' => ['nullable', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price_cents' => ['required', 'integer', 'min:0'],
            'items.*.tax_rate_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'items.*.tax_category' => ['nullable', 'string', 'max:32'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function toDto(): CreateSalesInvoiceData
    {
        $validated = $this->validated();

        /** @var list<array{product_id: string, description?: ?string, quantity: int, unit_price_cents: int, tax_rate_percent?: ?int, tax_category?: ?string, notes?: ?string}> $rawItems */
        $rawItems = array_values((array) $validated['items']);

        $items = array_map(
            fn (array $item): SalesInvoiceItemData => new SalesInvoiceItemData(
                productId: (string) $item['product_id'],
                quantity: (int) $item['quantity'],
                unitPriceCents: (int) $item['unit_price_cents'],
                taxRatePercent: isset($item['tax_rate_percent']) ? (int) $item['tax_rate_percent'] : 0,
                taxCategory: isset($item['tax_category']) ? (string) $item['tax_category'] : null,
                description: isset($item['description']) ? (string) $item['description'] : null,
                notes: isset($item['notes']) ? (string) $item['notes'] : null,
            ),
            $rawItems
        );

        return new CreateSalesInvoiceData(
            customerId: (string) $validated['customer_id'],
            issueDate: (string) $validated['issue_date'],
            dueDate: (string) $validated['due_date'],
            currency: (string) ($validated['currency'] ?? 'USD'),
            quotationId: isset($validated['sales_quotation_id']) ? (string) $validated['sales_quotation_id'] : null,
            warehouseId: isset($validated['warehouse_id']) ? (string) $validated['warehouse_id'] : null,
            notes: isset($validated['notes']) ? (string) $validated['notes'] : null,
            items: $items,
        );
    }
}
