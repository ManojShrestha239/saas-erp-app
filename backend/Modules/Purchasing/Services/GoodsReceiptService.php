<?php

declare(strict_types=1);

namespace Modules\Purchasing\Services;

use Carbon\Carbon;
use Illuminate\Database\DatabaseManager;
use Modules\Inventory\DTOs\RecordStockMovementData;
use Modules\Inventory\Enums\StockMovementType;
use Modules\Inventory\Services\StockService;
use Modules\Purchasing\DTOs\RecordGoodsReceiptData;
use Modules\Purchasing\Enums\PurchaseOrderStatus;
use Modules\Purchasing\Events\GoodsReceiptRecorded;
use Modules\Purchasing\Models\GoodsReceipt;
use Modules\Purchasing\Models\GoodsReceiptItem;
use Modules\Purchasing\Models\PurchaseOrder;
use Modules\Purchasing\Models\PurchaseOrderItem;
use Shared\Enums\DocumentType;
use Shared\Exceptions\ApiException;
use Shared\Services\DocumentNumberingService;

final class GoodsReceiptService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly DocumentNumberingService $numberingService,
        private readonly StockService $stockService,
    ) {}

    public function recordReceipt(RecordGoodsReceiptData $data): GoodsReceipt
    {
        return $this->db->connection('tenant')->transaction(function () use ($data): GoodsReceipt {
            /** @var PurchaseOrder|null $po */
            $po = PurchaseOrder::where('id', $data->purchaseOrderId)->lockForUpdate()->first();

            if ($po === null) {
                throw ApiException::notFound("Purchase order '{$data->purchaseOrderId}' not found.", 'PURCHASE_ORDER_NOT_FOUND');
            }

            if (! $po->status->canReceive()) {
                throw ApiException::unprocessable(
                    "Cannot receive goods on purchase order with status '{$po->status->value}'.",
                    'INVALID_PO_STATUS'
                );
            }

            if (empty($data->items)) {
                throw ApiException::unprocessable('At least one item must be received.', 'ITEMS_REQUIRED');
            }

            $receivedDate = Carbon::parse($data->receivedDate);
            $year = (int) $receivedDate->format('Y');

            $receiptNumber = $this->numberingService->generateNext(
                type: DocumentType::GoodsReceipt,
                year: $year,
            );

            /** @var GoodsReceipt $receipt */
            $receipt = GoodsReceipt::create([
                'receipt_number' => $receiptNumber,
                'purchase_order_id' => $po->id,
                'supplier_id' => $po->supplier_id,
                'warehouse_id' => $po->warehouse_id,
                'received_date' => $data->receivedDate,
                'notes' => $data->notes,
                'received_by_user_id' => $data->userId,
            ]);

            foreach ($data->items as $itemData) {
                if ($itemData->quantityReceived <= 0) {
                    throw ApiException::unprocessable('Received quantity must be greater than zero.', 'INVALID_QUANTITY');
                }

                /** @var PurchaseOrderItem|null $poItem */
                $poItem = PurchaseOrderItem::where('id', $itemData->purchaseOrderItemId)
                    ->where('purchase_order_id', $po->id)
                    ->lockForUpdate()
                    ->first();

                if ($poItem === null) {
                    throw ApiException::notFound(
                        "Line item '{$itemData->purchaseOrderItemId}' not found on purchase order '{$po->order_number}'.",
                        'PO_ITEM_NOT_FOUND'
                    );
                }

                $remainingToReceive = $poItem->quantity_ordered - $poItem->quantity_received;
                if ($itemData->quantityReceived > $remainingToReceive) {
                    throw ApiException::unprocessable(
                        "Cannot receive {$itemData->quantityReceived} units for line item. Remaining to receive is {$remainingToReceive}.",
                        'EXCESS_RECEIPT_QUANTITY'
                    );
                }

                // Increment received quantity on PO line
                $poItem->update([
                    'quantity_received' => $poItem->quantity_received + $itemData->quantityReceived,
                ]);

                // Create Goods Receipt Line Item
                GoodsReceiptItem::create([
                    'goods_receipt_id' => $receipt->id,
                    'purchase_order_item_id' => $poItem->id,
                    'product_id' => $poItem->product_id,
                    'quantity_received' => $itemData->quantityReceived,
                    'unit_cost_cents' => $poItem->unit_cost_cents,
                ]);

                // Atomically update inventory stock
                $this->stockService->recordMovement(new RecordStockMovementData(
                    warehouseId: $po->warehouse_id,
                    productId: $poItem->product_id,
                    type: StockMovementType::PurchaseReceipt,
                    quantity: $itemData->quantityReceived,
                    unitCostCents: $poItem->unit_cost_cents,
                    sourceType: 'goods_receipt',
                    sourceId: $receipt->id,
                    notes: "Goods Receipt {$receipt->receipt_number} for PO {$po->order_number}",
                    userId: $data->userId,
                ));
            }

            // Determine if PO is fully received or partially received
            $allItems = PurchaseOrderItem::where('purchase_order_id', $po->id)->get();
            $isFullyReceived = $allItems->every(
                fn (PurchaseOrderItem $item): bool => $item->quantity_received >= $item->quantity_ordered
            );

            $po->update([
                'status' => $isFullyReceived
                    ? PurchaseOrderStatus::Received
                    : PurchaseOrderStatus::PartiallyReceived,
            ]);

            GoodsReceiptRecorded::dispatch($receipt, $data->userId ?? '');

            return $receipt->load(['items', 'purchaseOrder']);
        }, 3);
    }
}
