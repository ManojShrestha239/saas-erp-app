<?php

declare(strict_types=1);

namespace Modules\Purchasing\Services;

use Carbon\Carbon;
use Illuminate\Database\DatabaseManager;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Accounting\Services\DocumentPostingService;
use Modules\Purchasing\DTOs\CreateSupplierBillData;
use Modules\Purchasing\DTOs\SupplierBillItemData;
use Modules\Purchasing\Enums\DebitNoteStatus;
use Modules\Purchasing\Enums\SupplierBillStatus;
use Modules\Purchasing\Events\SupplierBillPosted;
use Modules\Purchasing\Models\DebitNote;
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
        private readonly DocumentPostingService $documentPostingService,
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

    public function post(string $billId, string $userId): SupplierBill
    {
        return $this->postBill($billId, $userId);
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

            // Post journal entry to General Ledger atomically inside the transaction
            $this->documentPostingService->postSupplierBill(
                billId: $bill->id,
                billNumber: $bill->bill_number,
                billDate: Carbon::parse($bill->bill_date),
                subtotalCents: $bill->subtotal_cents,
                taxCents: $bill->tax_cents,
                totalCents: $bill->total_cents,
                currency: $bill->currency,
                userId: $userId,
            );

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

    public function recordPayment(string $id, int $amountCents): SupplierBill
    {
        return $this->db->connection('tenant')->transaction(function () use ($id, $amountCents): SupplierBill {
            /** @var SupplierBill|null $bill */
            $bill = SupplierBill::where('id', $id)->lockForUpdate()->first();

            if ($bill === null) {
                throw ApiException::notFound("Supplier bill '{$id}' not found.", 'BILL_NOT_FOUND');
            }

            if (! in_array($bill->status, [SupplierBillStatus::Posted, SupplierBillStatus::PartiallyPaid], true)) {
                throw ApiException::unprocessable(
                    "Payment cannot be recorded for bill in status '{$bill->status->value}'.",
                    'INVALID_BILL_STATUS'
                );
            }

            if ($amountCents <= 0) {
                throw ApiException::unprocessable('Payment amount must be greater than zero.', 'INVALID_PAYMENT_AMOUNT');
            }

            $newPaid = $bill->amount_paid_cents + $amountCents;
            $newDue = max(0, $bill->total_cents - $newPaid);
            $newStatus = $newDue === 0 ? SupplierBillStatus::Paid : SupplierBillStatus::PartiallyPaid;

            $bill->update([
                'amount_paid_cents' => $newPaid,
                'amount_due_cents' => $newDue,
                'status' => $newStatus,
            ]);

            return $bill->load('items');
        }, 3);
    }

    /**
     * @return array{
     *     as_of_date: string,
     *     current_cents: int,
     *     days_1_30_cents: int,
     *     days_31_60_cents: int,
     *     days_61_90_cents: int,
     *     days_over_90_cents: int,
     *     total_due_cents: int,
     *     bills: list<array{
     *         id: string,
     *         bill_number: string,
     *         supplier_invoice_number: ?string,
     *         bill_date: string,
     *         due_date: string,
     *         total_cents: int,
     *         amount_paid_cents: int,
     *         amount_due_cents: int,
     *         days_overdue: int,
     *         bucket: 'current'|'days_1_30'|'days_31_60'|'days_61_90'|'days_over_90'
     *     }>
     * }
     */
    public function getAgingBuckets(string $supplierId, ?Carbon $asOfDate = null): array
    {
        $asOf = ($asOfDate ?? Carbon::today())->startOfDay();

        $bills = SupplierBill::where('supplier_id', $supplierId)
            ->whereIn('status', [SupplierBillStatus::Posted, SupplierBillStatus::PartiallyPaid])
            ->where('amount_due_cents', '>', 0)
            ->orderBy('due_date', 'asc')
            ->get();

        $currentCents = 0;
        $days130Cents = 0;
        $days3160Cents = 0;
        $days6190Cents = 0;
        $daysOver90Cents = 0;
        $billsList = [];

        foreach ($bills as $bill) {
            $dueDate = Carbon::parse($bill->due_date)->startOfDay();
            $daysOverdue = (int) $dueDate->diffInDays($asOf, false);

            if ($daysOverdue <= 0) {
                $bucket = 'current';
                $currentCents += $bill->amount_due_cents;
            } elseif ($daysOverdue <= 30) {
                $bucket = 'days_1_30';
                $days130Cents += $bill->amount_due_cents;
            } elseif ($daysOverdue <= 60) {
                $bucket = 'days_31_60';
                $days3160Cents += $bill->amount_due_cents;
            } elseif ($daysOverdue <= 90) {
                $bucket = 'days_61_90';
                $days6190Cents += $bill->amount_due_cents;
            } else {
                $bucket = 'days_over_90';
                $daysOver90Cents += $bill->amount_due_cents;
            }

            $billsList[] = [
                'id' => (string) $bill->id,
                'bill_number' => $bill->bill_number,
                'supplier_invoice_number' => $bill->supplier_invoice_number,
                'bill_date' => Carbon::parse($bill->bill_date)->toDateString(),
                'due_date' => $dueDate->toDateString(),
                'total_cents' => $bill->total_cents,
                'amount_paid_cents' => $bill->amount_paid_cents,
                'amount_due_cents' => $bill->amount_due_cents,
                'days_overdue' => max(0, $daysOverdue),
                'bucket' => $bucket,
            ];
        }

        return [
            'as_of_date' => $asOf->toDateString(),
            'current_cents' => $currentCents,
            'days_1_30_cents' => $days130Cents,
            'days_31_60_cents' => $days3160Cents,
            'days_61_90_cents' => $days6190Cents,
            'days_over_90_cents' => $daysOver90Cents,
            'total_due_cents' => $currentCents + $days130Cents + $days3160Cents + $days6190Cents + $daysOver90Cents,
            'bills' => $billsList,
        ];
    }

    /**
     * @return array{
     *     as_of_date: string,
     *     summary: array{
     *         current_cents: int,
     *         days_1_30_cents: int,
     *         days_31_60_cents: int,
     *         days_61_90_cents: int,
     *         days_over_90_cents: int,
     *         total_due_cents: int,
     *     },
     *     suppliers: list<array{
     *         supplier_id: string,
     *         current_cents: int,
     *         days_1_30_cents: int,
     *         days_31_60_cents: int,
     *         days_61_90_cents: int,
     *         days_over_90_cents: int,
     *         total_due_cents: int,
     *         bill_count: int,
     *     }>
     * }
     */
    public function getApAgingSummary(?Carbon $asOfDate = null, ?string $supplierId = null): array
    {
        $asOf = ($asOfDate ?? Carbon::today())->startOfDay();

        $query = SupplierBill::query()
            ->whereIn('status', [SupplierBillStatus::Posted, SupplierBillStatus::PartiallyPaid])
            ->where('amount_due_cents', '>', 0);

        if ($supplierId !== null && trim($supplierId) !== '') {
            $query->where('supplier_id', $supplierId);
        }

        $bills = $query->orderBy('supplier_id')->orderBy('due_date')->get();

        $supplierBuckets = [];
        $summary = [
            'current_cents' => 0,
            'days_1_30_cents' => 0,
            'days_31_60_cents' => 0,
            'days_61_90_cents' => 0,
            'days_over_90_cents' => 0,
            'total_due_cents' => 0,
        ];

        foreach ($bills as $bill) {
            $sId = (string) $bill->supplier_id;
            if (! isset($supplierBuckets[$sId])) {
                $supplierBuckets[$sId] = [
                    'supplier_id' => $sId,
                    'current_cents' => 0,
                    'days_1_30_cents' => 0,
                    'days_31_60_cents' => 0,
                    'days_61_90_cents' => 0,
                    'days_over_90_cents' => 0,
                    'total_due_cents' => 0,
                    'bill_count' => 0,
                ];
            }

            $dueDate = Carbon::parse($bill->due_date)->startOfDay();
            $daysOverdue = (int) $dueDate->diffInDays($asOf, false);
            $dueCents = (int) $bill->amount_due_cents;

            if ($daysOverdue <= 0) {
                $supplierBuckets[$sId]['current_cents'] += $dueCents;
                $summary['current_cents'] += $dueCents;
            } elseif ($daysOverdue <= 30) {
                $supplierBuckets[$sId]['days_1_30_cents'] += $dueCents;
                $summary['days_1_30_cents'] += $dueCents;
            } elseif ($daysOverdue <= 60) {
                $supplierBuckets[$sId]['days_31_60_cents'] += $dueCents;
                $summary['days_31_60_cents'] += $dueCents;
            } elseif ($daysOverdue <= 90) {
                $supplierBuckets[$sId]['days_61_90_cents'] += $dueCents;
                $summary['days_61_90_cents'] += $dueCents;
            } else {
                $supplierBuckets[$sId]['days_over_90_cents'] += $dueCents;
                $summary['days_over_90_cents'] += $dueCents;
            }

            $supplierBuckets[$sId]['total_due_cents'] += $dueCents;
            $supplierBuckets[$sId]['bill_count'] += 1;
            $summary['total_due_cents'] += $dueCents;
        }

        return [
            'as_of_date' => $asOf->toDateString(),
            'summary' => $summary,
            'suppliers' => array_values($supplierBuckets),
        ];
    }

    /**
     * @return array{
     *     prior_billed_cents: int,
     *     transactions: list<array{
     *         id: string,
     *         date: string,
     *         due_date: ?string,
     *         document_number: string,
     *         type: string,
     *         description: string,
     *         debit_cents: int,
     *         credit_cents: int,
     *         amount_due_cents: int,
     *         status: string
     *     }>
     * }
     */
    public function getStatementTransactions(string $supplierId, ?Carbon $startDate = null, ?Carbon $endDate = null): array
    {
        $validStatuses = [
            SupplierBillStatus::Posted,
            SupplierBillStatus::PartiallyPaid,
            SupplierBillStatus::Paid,
        ];

        $priorBilledCents = 0;
        if ($startDate !== null) {
            $priorBilledCents = (int) SupplierBill::where('supplier_id', $supplierId)
                ->whereIn('status', $validStatuses)
                ->whereDate('bill_date', '<', $startDate->toDateString())
                ->sum('total_cents');

            $priorDebitedCents = (int) DebitNote::where('supplier_id', $supplierId)
                ->where('status', DebitNoteStatus::Posted)
                ->whereDate('issue_date', '<', $startDate->toDateString())
                ->sum('total_cents');

            $priorBilledCents -= $priorDebitedCents;
        }

        $query = SupplierBill::where('supplier_id', $supplierId)
            ->whereIn('status', $validStatuses);

        if ($startDate !== null) {
            $query->whereDate('bill_date', '>=', $startDate->toDateString());
        }

        if ($endDate !== null) {
            $query->whereDate('bill_date', '<=', $endDate->toDateString());
        }

        $bills = $query->orderBy('bill_date', 'asc')->orderBy('bill_number', 'asc')->get();

        $transactions = [];
        foreach ($bills as $bill) {
            $transactions[] = [
                'id' => (string) $bill->id,
                'date' => Carbon::parse($bill->bill_date)->toDateString(),
                'due_date' => Carbon::parse($bill->due_date)->toDateString(),
                'document_number' => $bill->bill_number,
                'type' => 'bill',
                'description' => "Supplier Bill {$bill->bill_number}".($bill->supplier_invoice_number ? " (Inv: {$bill->supplier_invoice_number})" : ''),
                'debit_cents' => 0,
                'credit_cents' => $bill->total_cents,
                'amount_due_cents' => $bill->amount_due_cents,
                'status' => $bill->status->value,
            ];
        }

        // Include posted debit notes
        $dnQuery = DebitNote::where('supplier_id', $supplierId)
            ->where('status', DebitNoteStatus::Posted);

        if ($startDate !== null) {
            $dnQuery->whereDate('issue_date', '>=', $startDate->toDateString());
        }

        if ($endDate !== null) {
            $dnQuery->whereDate('issue_date', '<=', $endDate->toDateString());
        }

        $debitNotes = $dnQuery->orderBy('issue_date', 'asc')->orderBy('debit_note_number', 'asc')->get();
        foreach ($debitNotes as $dn) {
            $transactions[] = [
                'id' => (string) $dn->id,
                'date' => Carbon::parse($dn->issue_date)->toDateString(),
                'due_date' => null,
                'document_number' => $dn->debit_note_number,
                'type' => 'debit_note',
                'description' => "Debit Note {$dn->debit_note_number}",
                'debit_cents' => $dn->total_cents,
                'credit_cents' => 0,
                'amount_due_cents' => 0,
                'status' => $dn->status->value,
            ];
        }

        return [
            'prior_billed_cents' => $priorBilledCents,
            'transactions' => $transactions,
        ];
    }

    /**
     * @return list<array{
     *     id: string,
     *     document_number: string,
     *     supplier_id: string,
     *     bill_date: string,
     *     status: string,
     *     currency: string,
     *     subtotal_cents: int,
     *     tax_cents: int,
     *     total_cents: int,
     *     items: list<array{
     *         id: string,
     *         product_id: ?string,
     *         description: ?string,
     *         quantity: int,
     *         unit_cost_cents: int,
     *         tax_rate_percent: int,
     *         subtotal_cents: int,
     *         tax_cents: int,
     *         total_cents: int
     *     }>
     * }>
     */
    public function getVatReportData(Carbon $startDate, Carbon $endDate): array
    {
        $validStatuses = [
            SupplierBillStatus::Posted,
            SupplierBillStatus::PartiallyPaid,
            SupplierBillStatus::Paid,
        ];

        $bills = SupplierBill::with('items')
            ->whereIn('status', $validStatuses)
            ->whereDate('bill_date', '>=', $startDate->toDateString())
            ->whereDate('bill_date', '<=', $endDate->toDateString())
            ->orderBy('bill_date', 'asc')
            ->orderBy('bill_number', 'asc')
            ->get();

        $result = [];
        foreach ($bills as $bill) {
            $items = [];
            foreach ($bill->items as $item) {
                $items[] = [
                    'id' => (string) $item->id,
                    'product_id' => $item->product_id !== null ? (string) $item->product_id : null,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_cost_cents' => $item->unit_cost_cents,
                    'tax_rate_percent' => $item->tax_rate_percent,
                    'subtotal_cents' => $item->subtotal_cents,
                    'tax_cents' => $item->tax_cents,
                    'total_cents' => $item->total_cents,
                ];
            }

            $result[] = [
                'id' => (string) $bill->id,
                'document_number' => $bill->bill_number,
                'supplier_id' => (string) $bill->supplier_id,
                'bill_date' => Carbon::parse($bill->bill_date)->toDateString(),
                'status' => $bill->status->value,
                'currency' => $bill->currency,
                'subtotal_cents' => $bill->subtotal_cents,
                'tax_cents' => $bill->tax_cents,
                'total_cents' => $bill->total_cents,
                'items' => $items,
            ];
        }

        return $result;
    }
}
