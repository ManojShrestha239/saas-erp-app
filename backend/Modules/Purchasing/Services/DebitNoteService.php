<?php

declare(strict_types=1);

namespace Modules\Purchasing\Services;

use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\DatabaseManager;
use Modules\Accounting\Services\DocumentPostingService;
use Modules\Inventory\DTOs\RecordStockMovementData;
use Modules\Inventory\Enums\StockMovementType;
use Modules\Inventory\Services\StockService;
use Modules\Purchasing\DTOs\CreateDebitNoteData;
use Modules\Purchasing\Enums\DebitNoteStatus;
use Modules\Purchasing\Enums\SupplierBillStatus;
use Modules\Purchasing\Events\DebitNotePosted;
use Modules\Purchasing\Models\DebitNote;
use Modules\Purchasing\Models\SupplierBill;
use Shared\Enums\DocumentType;
use Shared\Exceptions\ApiException;
use Shared\Services\DocumentNumberingService;
use Shared\Tenancy\TenantContext;

final class DebitNoteService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly DocumentNumberingService $numberingService,
        private readonly StockService $stockService,
        private readonly DocumentPostingService $documentPostingService,
    ) {}

    public function create(CreateDebitNoteData $data, string $userId): DebitNote
    {
        return $this->db->connection('tenant')->transaction(function () use ($data, $userId): DebitNote {
            if (empty($data->items)) {
                throw ApiException::unprocessable('Debit note must contain at least one item.', 'EMPTY_DEBIT_NOTE');
            }

            // Validate linked bill if provided
            if ($data->supplierBillId !== null) {
                /** @var SupplierBill|null $bill */
                $bill = SupplierBill::with('items')->where('id', $data->supplierBillId)->first();
                if ($bill === null) {
                    throw ApiException::notFound("Supplier bill '{$data->supplierBillId}' not found.", 'BILL_NOT_FOUND');
                }
                if ($bill->supplier_id !== $data->supplierId) {
                    throw ApiException::unprocessable('Supplier does not match the bill supplier.', 'SUPPLIER_MISMATCH');
                }
                if ($bill->status === SupplierBillStatus::Draft || $bill->status === SupplierBillStatus::Void) {
                    throw ApiException::unprocessable('Cannot debit a draft or void bill.', 'INVALID_BILL_STATUS');
                }
            }

            $year = (int) Carbon::parse($data->issueDate)->format('Y');
            $debitNoteNumber = $this->numberingService->generateNext(
                type: DocumentType::DebitNote,
                year: $year,
            );

            $subtotalCents = 0;
            $taxCents = 0;
            $totalCents = 0;

            /** @var list<array{product_id: string, description: ?string, quantity: int, unit_cost_cents: int, tax_rate_percent: int, tax_category: ?string, subtotal_cents: int, tax_cents: int, total_cents: int, notes: ?string}> $itemAttributes */
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
                    'product_id' => $item->productId,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_cost_cents' => $item->unitCostCents,
                    'tax_rate_percent' => $item->taxRatePercent,
                    'tax_category' => $item->taxCategory,
                    'subtotal_cents' => $lineSubtotal,
                    'tax_cents' => $lineTax,
                    'total_cents' => $lineTotal,
                    'notes' => $item->notes,
                ];
            }

            /** @var DebitNote $debitNote */
            $debitNote = DebitNote::create([
                'debit_note_number' => $debitNoteNumber,
                'supplier_id' => $data->supplierId,
                'supplier_bill_id' => $data->supplierBillId,
                'warehouse_id' => $data->warehouseId,
                'status' => DebitNoteStatus::Draft,
                'issue_date' => $data->issueDate,
                'currency' => $data->currency,
                'subtotal_cents' => $subtotalCents,
                'tax_cents' => $taxCents,
                'total_cents' => $totalCents,
                'return_stock' => $data->returnStock,
                'reason' => $data->reason,
                'notes' => $data->notes,
                'created_by_user_id' => $userId,
            ]);

            foreach ($itemAttributes as $attrs) {
                $debitNote->items()->create($attrs);
            }

            return $debitNote->load('items');
        }, 3);
    }

    public function update(string $id, CreateDebitNoteData $data): DebitNote
    {
        return $this->db->connection('tenant')->transaction(function () use ($id, $data): DebitNote {
            /** @var DebitNote|null $debitNote */
            $debitNote = DebitNote::with('items')->where('id', $id)->lockForUpdate()->first();

            if ($debitNote === null) {
                throw ApiException::notFound("Debit note '{$id}' not found.", 'DEBIT_NOTE_NOT_FOUND');
            }

            if ($debitNote->status->isImmutable()) {
                throw ApiException::unprocessable(
                    "Debit note is in immutable status '{$debitNote->status->value}' and cannot be modified.",
                    'DEBIT_NOTE_IMMUTABLE'
                );
            }

            if (empty($data->items)) {
                throw ApiException::unprocessable('Debit note must contain at least one item.', 'EMPTY_DEBIT_NOTE');
            }

            $subtotalCents = 0;
            $taxCents = 0;
            $totalCents = 0;

            /** @var list<array{product_id: string, description: ?string, quantity: int, unit_cost_cents: int, tax_rate_percent: int, tax_category: ?string, subtotal_cents: int, tax_cents: int, total_cents: int, notes: ?string}> $itemAttributes */
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
                    'product_id' => $item->productId,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_cost_cents' => $item->unitCostCents,
                    'tax_rate_percent' => $item->taxRatePercent,
                    'tax_category' => $item->taxCategory,
                    'subtotal_cents' => $lineSubtotal,
                    'tax_cents' => $lineTax,
                    'total_cents' => $lineTotal,
                    'notes' => $item->notes,
                ];
            }

            $debitNote->update([
                'supplier_id' => $data->supplierId,
                'supplier_bill_id' => $data->supplierBillId,
                'warehouse_id' => $data->warehouseId,
                'issue_date' => $data->issueDate,
                'currency' => $data->currency,
                'subtotal_cents' => $subtotalCents,
                'tax_cents' => $taxCents,
                'total_cents' => $totalCents,
                'return_stock' => $data->returnStock,
                'reason' => $data->reason,
                'notes' => $data->notes,
            ]);

            $debitNote->items()->delete();
            foreach ($itemAttributes as $attrs) {
                $debitNote->items()->create($attrs);
            }

            return $debitNote->load('items');
        }, 3);
    }

    public function delete(string $id): void
    {
        $this->db->connection('tenant')->transaction(function () use ($id): void {
            /** @var DebitNote|null $debitNote */
            $debitNote = DebitNote::where('id', $id)->lockForUpdate()->first();

            if ($debitNote === null) {
                throw ApiException::notFound("Debit note '{$id}' not found.", 'DEBIT_NOTE_NOT_FOUND');
            }

            if ($debitNote->status->isImmutable()) {
                throw ApiException::unprocessable(
                    "Debit note is in immutable status '{$debitNote->status->value}' and cannot be deleted.",
                    'DEBIT_NOTE_IMMUTABLE'
                );
            }

            $debitNote->delete();
        }, 3);
    }

    public function post(string $id, string $userId): DebitNote
    {
        return $this->db->connection('tenant')->transaction(function () use ($id, $userId): DebitNote {
            /** @var DebitNote|null $debitNote */
            $debitNote = DebitNote::with('items')->where('id', $id)->lockForUpdate()->first();

            if ($debitNote === null) {
                throw ApiException::notFound("Debit note '{$id}' not found.", 'DEBIT_NOTE_NOT_FOUND');
            }

            if ($debitNote->status !== DebitNoteStatus::Draft) {
                throw ApiException::unprocessable(
                    "Debit note cannot be posted from '{$debitNote->status->value}' status.",
                    'INVALID_DEBIT_NOTE_STATUS'
                );
            }

            // Return stock to supplier if enabled (outbound movement)
            if ($debitNote->return_stock) {
                foreach ($debitNote->items as $item) {
                    if ($item->quantity <= 0) {
                        continue;
                    }

                    $this->stockService->recordMovement(new RecordStockMovementData(
                        warehouseId: $debitNote->warehouse_id,
                        productId: $item->product_id,
                        type: StockMovementType::PurchaseReturn,
                        quantity: $item->quantity,
                        unitCostCents: $item->unit_cost_cents,
                        sourceType: 'debit_note',
                        sourceId: $debitNote->id,
                        userId: $userId,
                        notes: "Vendor Return for Debit Note {$debitNote->debit_note_number}",
                    ));
                }
            }

            // Reduce linked Supplier Bill amount_due_cents if linked
            if (! empty($debitNote->supplier_bill_id)) {
                /** @var SupplierBill|null $bill */
                $bill = SupplierBill::where('id', $debitNote->supplier_bill_id)->lockForUpdate()->first();
                if ($bill !== null) {
                    $reduction = min($bill->amount_due_cents, $debitNote->total_cents);
                    $newAmountDue = $bill->amount_due_cents - $reduction;
                    $bill->amount_due_cents = $newAmountDue;
                    if ($newAmountDue === 0 && $bill->status !== SupplierBillStatus::Paid) {
                        $bill->status = SupplierBillStatus::Paid;
                    }
                    $bill->save();
                }
            }

            $debitNote->update([
                'status' => DebitNoteStatus::Posted,
                'posted_at' => now(),
            ]);

            // Post General Ledger double-entry
            $this->documentPostingService->postDebitNote(
                debitNoteId: $debitNote->id,
                debitNoteNumber: $debitNote->debit_note_number,
                issueDate: Carbon::parse($debitNote->issue_date),
                subtotalCents: $debitNote->subtotal_cents,
                taxCents: $debitNote->tax_cents,
                totalCents: $debitNote->total_cents,
                currency: $debitNote->currency,
                userId: $userId,
            );

            $tenant = TenantContext::get();
            $tenantId = $tenant !== null ? (string) $tenant->id : '';

            DebitNotePosted::dispatch($debitNote, $tenantId, $userId);

            return $debitNote->load('items');
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, DebitNote>
     */
    public function list(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $query = DebitNote::with('items')->orderByDesc('created_at');

        if (! empty($filters['supplier_id'])) {
            $query->where('supplier_id', $filters['supplier_id']);
        }

        if (! empty($filters['supplier_bill_id'])) {
            $query->where('supplier_bill_id', $filters['supplier_bill_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['search'])) {
            $search = (string) $filters['search'];
            $query->where('debit_note_number', 'like', "%{$search}%");
        }

        return $query->paginate($perPage);
    }

    public function findById(string $id): ?DebitNote
    {
        return DebitNote::with(['items', 'bill'])->find($id);
    }

    /**
     * @return list<array{
     *     id: string,
     *     document_number: string,
     *     supplier_id: string,
     *     supplier_bill_id: string,
     *     issue_date: string,
     *     status: string,
     *     currency: string,
     *     subtotal_cents: int,
     *     tax_cents: int,
     *     total_cents: int,
     *     items: list<array{
     *         id: string,
     *         product_id: string,
     *         description: ?string,
     *         quantity: int,
     *         unit_cost_cents: int,
     *         tax_rate_percent: int,
     *         tax_category: ?string,
     *         subtotal_cents: int,
     *         tax_cents: int,
     *         total_cents: int
     *     }>
     * }>
     */
    public function getVatReportData(Carbon $startDate, Carbon $endDate): array
    {
        $debitNotes = DebitNote::with('items')
            ->where('status', DebitNoteStatus::Posted)
            ->whereDate('issue_date', '>=', $startDate->toDateString())
            ->whereDate('issue_date', '<=', $endDate->toDateString())
            ->orderBy('issue_date', 'asc')
            ->orderBy('debit_note_number', 'asc')
            ->get();

        $result = [];
        foreach ($debitNotes as $dn) {
            $items = [];
            foreach ($dn->items as $item) {
                $items[] = [
                    'id' => (string) $item->id,
                    'product_id' => (string) $item->product_id,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_cost_cents' => $item->unit_cost_cents,
                    'tax_rate_percent' => $item->tax_rate_percent,
                    'tax_category' => $item->tax_category,
                    'subtotal_cents' => $item->subtotal_cents,
                    'tax_cents' => $item->tax_cents,
                    'total_cents' => $item->total_cents,
                ];
            }

            $result[] = [
                'id' => (string) $dn->id,
                'document_number' => $dn->debit_note_number,
                'supplier_id' => (string) $dn->supplier_id,
                'supplier_bill_id' => (string) $dn->supplier_bill_id,
                'issue_date' => Carbon::parse($dn->issue_date)->toDateString(),
                'status' => $dn->status->value,
                'currency' => $dn->currency,
                'subtotal_cents' => $dn->subtotal_cents,
                'tax_cents' => $dn->tax_cents,
                'total_cents' => $dn->total_cents,
                'items' => $items,
            ];
        }

        return $result;
    }
}
