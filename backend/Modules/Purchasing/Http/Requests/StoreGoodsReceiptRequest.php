<?php

declare(strict_types=1);

namespace Modules\Purchasing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Purchasing\DTOs\GoodsReceiptItemData;
use Modules\Purchasing\DTOs\RecordGoodsReceiptData;

final class StoreGoodsReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        return $user->can('purchases.manage')
            || $user->can('purchases.po.draft')
            || $user->can('stock.adjust');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'received_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.purchase_order_item_id' => [
                'required',
                'string',
                Rule::exists('tenant.purchase_order_items', 'id'),
            ],
            'items.*.quantity_received' => ['required', 'integer', 'min:1'],
        ];
    }

    public function toDto(string $purchaseOrderId): RecordGoodsReceiptData
    {
        $validated = $this->validated();

        /** @var string|null $userId */
        $userId = $this->user()?->getAuthIdentifier();

        /** @var list<array{purchase_order_item_id: string, quantity_received: int}> $rawItems */
        $rawItems = array_values((array) $validated['items']);

        $items = array_map(
            fn (array $item): GoodsReceiptItemData => new GoodsReceiptItemData(
                purchaseOrderItemId: (string) $item['purchase_order_item_id'],
                quantityReceived: (int) $item['quantity_received'],
            ),
            $rawItems
        );

        return new RecordGoodsReceiptData(
            purchaseOrderId: $purchaseOrderId,
            receivedDate: (string) $validated['received_date'],
            notes: isset($validated['notes']) ? (string) $validated['notes'] : null,
            userId: $userId,
            items: $items,
        );
    }
}
