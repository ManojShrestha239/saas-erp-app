<?php

declare(strict_types=1);

namespace Modules\Purchasing\Services;

use Carbon\Carbon;
use Illuminate\Database\DatabaseManager;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Purchasing\DTOs\CreatePurchaseOrderData;
use Modules\Purchasing\Enums\PurchaseOrderStatus;
use Modules\Purchasing\Events\PurchaseOrderApproved;
use Modules\Purchasing\Models\PurchaseOrder;
use Shared\Enums\DocumentType;
use Shared\Exceptions\ApiException;
use Shared\Services\DocumentNumberingService;

final class PurchaseOrderService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly DocumentNumberingService $numberingService,
    ) {}

    public function create(CreatePurchaseOrderData $data): PurchaseOrder
    {
        return $this->db->connection('tenant')->transaction(function () use ($data): PurchaseOrder {
            if (empty($data->items)) {
                throw ApiException::unprocessable('At least one line item is required for a purchase order.', 'ITEMS_REQUIRED');
            }

            $orderDate = Carbon::parse($data->orderDate);
            $year = (int) $orderDate->format('Y');

            $orderNumber = $this->numberingService->generateNext(
                type: DocumentType::PurchaseOrder,
                year: $year,
            );

            $subtotalCents = 0;
            $taxCents = 0;
            $totalCents = 0;

            /** @var list<array{product_id: string, quantity_ordered: int, quantity_received: int, unit_cost_cents: int, tax_rate_percent: int, tax_category: ?string, subtotal_cents: int, tax_cents: int, total_cents: int, notes: ?string}> $itemAttributes */
            $itemAttributes = [];

            foreach ($data->items as $item) {
                if ($item->quantityOrdered <= 0) {
                    throw ApiException::unprocessable('Item quantity must be greater than zero.', 'INVALID_QUANTITY');
                }
                if ($item->unitCostCents < 0) {
                    throw ApiException::unprocessable('Item unit cost cannot be negative.', 'INVALID_COST');
                }

                $lineSubtotal = $item->quantityOrdered * $item->unitCostCents;
                $lineTax = (int) round($lineSubtotal * ($item->taxRatePercent / 100));
                $lineTotal = $lineSubtotal + $lineTax;

                $subtotalCents += $lineSubtotal;
                $taxCents += $lineTax;
                $totalCents += $lineTotal;

                $itemAttributes[] = [
                    'product_id' => $item->productId,
                    'quantity_ordered' => $item->quantityOrdered,
                    'quantity_received' => 0,
                    'unit_cost_cents' => $item->unitCostCents,
                    'tax_rate_percent' => $item->taxRatePercent,
                    'tax_category' => $item->taxCategory,
                    'subtotal_cents' => $lineSubtotal,
                    'tax_cents' => $lineTax,
                    'total_cents' => $lineTotal,
                    'notes' => $item->notes,
                ];
            }

            /** @var PurchaseOrder $po */
            $po = PurchaseOrder::create([
                'order_number' => $orderNumber,
                'supplier_id' => $data->supplierId,
                'warehouse_id' => $data->warehouseId,
                'status' => PurchaseOrderStatus::Draft,
                'order_date' => $data->orderDate,
                'expected_delivery_date' => $data->expectedDeliveryDate,
                'currency' => $data->currency,
                'subtotal_cents' => $subtotalCents,
                'tax_cents' => $taxCents,
                'total_cents' => $totalCents,
                'notes' => $data->notes,
                'created_by_user_id' => $data->userId,
            ]);

            foreach ($itemAttributes as $attr) {
                $po->items()->create($attr);
            }

            return $po->load('items');
        }, 3);
    }

    public function approve(string $poId, string $userId): PurchaseOrder
    {
        return $this->db->connection('tenant')->transaction(function () use ($poId, $userId): PurchaseOrder {
            /** @var PurchaseOrder|null $po */
            $po = PurchaseOrder::where('id', $poId)->lockForUpdate()->first();

            if ($po === null) {
                throw ApiException::notFound("Purchase order '{$poId}' not found.", 'PURCHASE_ORDER_NOT_FOUND');
            }

            if (! $po->status->canBeApproved()) {
                throw ApiException::unprocessable(
                    "Purchase order cannot be approved from '{$po->status->value}' status.",
                    'INVALID_PO_STATUS'
                );
            }

            $po->update([
                'status' => PurchaseOrderStatus::Approved,
                'approved_by_user_id' => $userId,
                'approved_at' => now(),
            ]);

            PurchaseOrderApproved::dispatch($po, $userId);

            return $po->load('items');
        }, 3);
    }

    public function cancel(string $poId, string $userId): PurchaseOrder
    {
        return $this->db->connection('tenant')->transaction(function () use ($poId): PurchaseOrder {
            /** @var PurchaseOrder|null $po */
            $po = PurchaseOrder::where('id', $poId)->lockForUpdate()->first();

            if ($po === null) {
                throw ApiException::notFound("Purchase order '{$poId}' not found.", 'PURCHASE_ORDER_NOT_FOUND');
            }

            if (! $po->status->canBeCancelled()) {
                throw ApiException::unprocessable(
                    "Purchase order cannot be cancelled from '{$po->status->value}' status.",
                    'INVALID_PO_STATUS'
                );
            }

            $po->update([
                'status' => PurchaseOrderStatus::Cancelled,
            ]);

            return $po->load('items');
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, PurchaseOrder>
     */
    public function list(array $filters): LengthAwarePaginator
    {
        $query = PurchaseOrder::query()->with('items');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['supplier_id'])) {
            $query->where('supplier_id', $filters['supplier_id']);
        }

        if (! empty($filters['warehouse_id'])) {
            $query->where('warehouse_id', $filters['warehouse_id']);
        }

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where('order_number', 'ilike', "%{$search}%");
        }

        $perPage = min(max((int) ($filters['per_page'] ?? 25), 1), 100);

        /** @var LengthAwarePaginator<int, PurchaseOrder> $paginator */
        $paginator = $query->orderByDesc('created_at')->paginate($perPage);

        return $paginator;
    }

    public function find(string $id): ?PurchaseOrder
    {
        return PurchaseOrder::with(['items', 'goodsReceipts.items'])->find($id);
    }
}
