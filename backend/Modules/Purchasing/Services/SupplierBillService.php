<?php

declare(strict_types=1);

namespace Modules\Purchasing\Services;

use Carbon\Carbon;
use Illuminate\Database\DatabaseManager;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Purchasing\DTOs\CreateSupplierBillData;
use Modules\Purchasing\DTOs\SupplierBillItemData;
use Modules\Purchasing\Enums\SupplierBillStatus;
use Modules\Purchasing\Events\SupplierBillPosted;
use Modules\Purchasing\Models\PurchaseOrder;
use Modules\Purchasing\Models\SupplierBill;
use Shared\Enums\DocumentType;
use Shared\Exceptions\ApiException;
use Shared\Services\DocumentNumberingService;
use Shared\Tenancy\TenantContext;

final class SupplierBillService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly DocumentNumberingService $numberingService,
    ) {}

    public function create(CreateSupplierBillData $data): SupplierBill
    {
        return $this->db->connection('tenant')->transaction(function () use ($data): SupplierBill {
            if (empty($data->items)) {
                throw ApiException::unprocessable('At least one item is required on a supplier bill.', 'ITEMS_REQUIRED');
            }

            $billDate = Carbon::parse($data->billDate);
            $year = (int) $billDate->format('Y');

            $billNumber = $this->numberingService->generateNext(
                type: DocumentType::SupplierBill,
                year: $year,
            );

            $subtotalCents = 0;
            $taxCents = 0;
            $totalCents = 0;

            /** @var list<array{description: string, quantity: int, unit_cost_cents: int, tax_rate_percent: int, subtotal_cents: int, tax_cents: int, total_cents: int, purchase_order_item_id: ?string, product_id: ?string}> $itemAttributes */
            $itemAttributes = [];

            foreach ($data->items as $item) {
                if ($item->quantity <= 0) {
                    throw ApiException::unprocessable('Item quantity must be greater than zero.', 'INVALID_QUANTITY');
                }
                if ($item->unitCostCents < 0) {
                    throw ApiException::unprocessable('Item unit cost cannot be negative.', 'INVALID_COST');
                }

                $lineSubtotal = $item->quantity * $item->unitCostCents;
                $lineTax = (int) round($lineSubtotal * ($item->taxRatePercent / 100));
                $lineTotal = $lineSubtotal + $lineTax;

                $subtotalCents += $lineSubtotal;
                $taxCents += $lineTax;
                $totalCents += $lineTotal;

                $itemAttributes[] = [
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_cost_cents' => $item->unitCostCents,
                    'tax_rate_percent' => $item->taxRatePercent,
                    'subtotal_cents' => $lineSubtotal,
                    'tax_cents' => $lineTax,
                    'total_cents' => $lineTotal,
                    'purchase_order_item_id' => $item->purchaseOrderItemId,
                    'product_id' => $item->productId,
                ];
            }

            /** @var SupplierBill $bill */
            $bill = SupplierBill::create([
                'bill_number' => $billNumber,
                'supplier_invoice_number' => $data->supplierInvoiceNumber,
                'supplier_id' => $data->supplierId,
                'purchase_order_id' => $data->purchaseOrderId,
                'bill_date' => $data->billDate,
                'due_date' => $data->dueDate,
                'currency' => $data->currency,
                'subtotal_cents' => $subtotalCents,
                'tax_cents' => $taxCents,
                'total_cents' => $totalCents,
                'amount_due_cents' => 0,
                'amount_paid_cents' => 0,
                'status' => SupplierBillStatus::Draft,
                'notes' => $data->notes,
            ]);

            foreach ($itemAttributes as $attr) {
                $bill->items()->create($attr);
            }

            return $bill->load('items');
        }, 3);
    }

    public function createFromPurchaseOrder(
        string $poId,
        string $billDate,
        string $dueDate,
        ?string $supplierInvoiceNumber = null,
        ?string $userId = null,
    ): SupplierBill {
        return $this->db->connection('tenant')->transaction(function () use ($poId, $billDate, $dueDate, $supplierInvoiceNumber, $userId): SupplierBill {
            /** @var PurchaseOrder|null $po */
            $po = PurchaseOrder::with('items')->find($poId);

            if ($po === null) {
                throw ApiException::notFound("Purchase order '{$poId}' not found.", 'PURCHASE_ORDER_NOT_FOUND');
            }

            $itemsData = [];
            foreach ($po->items as $poItem) {
                $itemsData[] = new SupplierBillItemData(
                    description: "Item from PO {$po->order_number}",
                    quantity: $poItem->quantity_ordered,
                    unitCostCents: $poItem->unit_cost_cents,
                    taxRatePercent: $poItem->tax_rate_percent,
                    purchaseOrderItemId: $poItem->id,
                    productId: $poItem->product_id,
                );
            }

            return $this->create(new CreateSupplierBillData(
                supplierId: $po->supplier_id,
                billDate: $billDate,
                dueDate: $dueDate,
                supplierInvoiceNumber: $supplierInvoiceNumber,
                purchaseOrderId: $po->id,
                currency: $po->currency,
                userId: $userId,
                items: $itemsData,
            ));
        }, 3);
    }

    public function postBill(string $billId, string $userId): SupplierBill
    {
        return $this->db->connection('tenant')->transaction(function () use ($billId, $userId): SupplierBill {
            /** @var SupplierBill|null $bill */
            $bill = SupplierBill::where('id', $billId)->lockForUpdate()->first();

            if ($bill === null) {
                throw ApiException::notFound("Supplier bill '{$billId}' not found.", 'BILL_NOT_FOUND');
            }

            if (! $bill->status->canBePosted()) {
                throw ApiException::unprocessable(
                    "Supplier bill cannot be posted from '{$bill->status->value}' status.",
                    'INVALID_BILL_STATUS'
                );
            }

            $bill->update([
                'status' => SupplierBillStatus::Posted,
                'posted_at' => now(),
                'posted_by_user_id' => $userId,
                'amount_due_cents' => $bill->total_cents,
                'amount_paid_cents' => 0,
            ]);

            $tenant = TenantContext::get();
            $tenantId = $tenant !== null ? (string) $tenant->id : '';

            SupplierBillPosted::dispatch($bill, $tenantId, $userId);

            return $bill->load('items');
        }, 3);
    }

    public function getOutstandingPayableCents(string $supplierId): int
    {
        return (int) SupplierBill::where('supplier_id', $supplierId)
            ->whereIn('status', [SupplierBillStatus::Posted, SupplierBillStatus::PartiallyPaid])
            ->sum('amount_due_cents');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, SupplierBill>
     */
    public function list(array $filters): LengthAwarePaginator
    {
        $query = SupplierBill::query()->with('items');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['supplier_id'])) {
            $query->where('supplier_id', $filters['supplier_id']);
        }

        if (! empty($filters['purchase_order_id'])) {
            $query->where('purchase_order_id', $filters['purchase_order_id']);
        }

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($q) use ($search): void {
                $q->where('bill_number', 'ilike', "%{$search}%")
                    ->orWhere('supplier_invoice_number', 'ilike', "%{$search}%");
            });
        }

        $perPage = min(max((int) ($filters['per_page'] ?? 25), 1), 100);

        /** @var LengthAwarePaginator<int, SupplierBill> $paginator */
        $paginator = $query->orderByDesc('created_at')->paginate($perPage);

        return $paginator;
    }

    public function find(string $id): ?SupplierBill
    {
        return SupplierBill::with(['items', 'purchaseOrder'])->find($id);
    }
}
