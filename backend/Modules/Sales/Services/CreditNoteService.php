<?php

declare(strict_types=1);

namespace Modules\Sales\Services;

use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\DatabaseManager;
use Modules\Accounting\Services\DocumentPostingService;
use Modules\Inventory\DTOs\RecordStockMovementData;
use Modules\Inventory\Enums\StockMovementType;
use Modules\Inventory\Services\StockService;
use Modules\Sales\DTOs\CreateCreditNoteData;
use Modules\Sales\Enums\CreditNoteStatus;
use Modules\Sales\Enums\SalesInvoiceStatus;
use Modules\Sales\Events\CreditNotePosted;
use Modules\Sales\Models\CreditNote;
use Modules\Sales\Models\SalesInvoice;
use Shared\Enums\DocumentType;
use Shared\Exceptions\ApiException;
use Shared\Services\DocumentNumberingService;
use Shared\Tenancy\TenantContext;

final class CreditNoteService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly DocumentNumberingService $numberingService,
        private readonly StockService $stockService,
        private readonly DocumentPostingService $documentPostingService,
    ) {}

    public function create(CreateCreditNoteData $data, string $userId): CreditNote
    {
        return $this->db->connection('tenant')->transaction(function () use ($data, $userId): CreditNote {
            if (empty($data->items)) {
                throw ApiException::unprocessable('Credit note must contain at least one item.', 'EMPTY_CREDIT_NOTE');
            }

            // Validate linked invoice if provided
            if ($data->salesInvoiceId !== null) {
                /** @var SalesInvoice|null $invoice */
                $invoice = SalesInvoice::with('items')->where('id', $data->salesInvoiceId)->first();
                if ($invoice === null) {
                    throw ApiException::notFound("Sales invoice '{$data->salesInvoiceId}' not found.", 'INVOICE_NOT_FOUND');
                }
                if ($invoice->customer_id !== $data->customerId) {
                    throw ApiException::unprocessable('Customer does not match the invoice customer.', 'CUSTOMER_MISMATCH');
                }
                if ($invoice->status === SalesInvoiceStatus::Draft || $invoice->status === SalesInvoiceStatus::Void) {
                    throw ApiException::unprocessable('Cannot credit a draft or void invoice.', 'INVALID_INVOICE_STATUS');
                }
            }

            $year = (int) Carbon::parse($data->issueDate)->format('Y');
            $creditNoteNumber = $this->numberingService->generateNext(
                type: DocumentType::CreditNote,
                year: $year,
            );

            $subtotalCents = 0;
            $taxCents = 0;
            $totalCents = 0;

            /** @var list<array{product_id: string, description: ?string, quantity: int, unit_price_cents: int, tax_rate_percent: int, tax_category: ?string, subtotal_cents: int, tax_cents: int, total_cents: int, cogs_unit_cost_cents: int, notes: ?string}> $itemAttributes */
            $itemAttributes = [];

            foreach ($data->items as $item) {
                if ($item->quantity <= 0) {
                    throw ApiException::unprocessable('Item quantity must be greater than zero.', 'INVALID_QUANTITY');
                }
                if ($item->unitPriceCents < 0) {
                    throw ApiException::unprocessable('Item unit price cannot be negative.', 'INVALID_PRICE');
                }

                $lineSubtotal = $item->quantity * $item->unitPriceCents;
                $lineTax = (int) round($lineSubtotal * ($item->taxRatePercent / 100));
                $lineTotal = $lineSubtotal + $lineTax;

                $subtotalCents += $lineSubtotal;
                $taxCents += $lineTax;
                $totalCents += $lineTotal;

                $itemAttributes[] = [
                    'product_id' => $item->productId,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_price_cents' => $item->unitPriceCents,
                    'tax_rate_percent' => $item->taxRatePercent,
                    'tax_category' => $item->taxCategory,
                    'subtotal_cents' => $lineSubtotal,
                    'tax_cents' => $lineTax,
                    'total_cents' => $lineTotal,
                    'cogs_unit_cost_cents' => $item->cogsUnitCostCents,
                    'notes' => $item->notes,
                ];
            }

            /** @var CreditNote $creditNote */
            $creditNote = CreditNote::create([
                'credit_note_number' => $creditNoteNumber,
                'customer_id' => $data->customerId,
                'sales_invoice_id' => $data->salesInvoiceId,
                'warehouse_id' => $data->warehouseId,
                'status' => CreditNoteStatus::Draft,
                'issue_date' => $data->issueDate,
                'currency' => $data->currency,
                'subtotal_cents' => $subtotalCents,
                'tax_cents' => $taxCents,
                'total_cents' => $totalCents,
                'restock' => $data->restock,
                'reason' => $data->reason,
                'notes' => $data->notes,
                'created_by_user_id' => $userId,
            ]);

            foreach ($itemAttributes as $attrs) {
                $creditNote->items()->create($attrs);
            }

            return $creditNote->load('items');
        }, 3);
    }

    public function update(string $id, CreateCreditNoteData $data): CreditNote
    {
        return $this->db->connection('tenant')->transaction(function () use ($id, $data): CreditNote {
            /** @var CreditNote|null $creditNote */
            $creditNote = CreditNote::with('items')->where('id', $id)->lockForUpdate()->first();

            if ($creditNote === null) {
                throw ApiException::notFound("Credit note '{$id}' not found.", 'CREDIT_NOTE_NOT_FOUND');
            }

            if ($creditNote->status->isImmutable()) {
                throw ApiException::unprocessable(
                    "Credit note is in immutable status '{$creditNote->status->value}' and cannot be modified.",
                    'CREDIT_NOTE_IMMUTABLE'
                );
            }

            if (empty($data->items)) {
                throw ApiException::unprocessable('Credit note must contain at least one item.', 'EMPTY_CREDIT_NOTE');
            }

            $subtotalCents = 0;
            $taxCents = 0;
            $totalCents = 0;

            /** @var list<array{product_id: string, description: ?string, quantity: int, unit_price_cents: int, tax_rate_percent: int, tax_category: ?string, subtotal_cents: int, tax_cents: int, total_cents: int, cogs_unit_cost_cents: int, notes: ?string}> $itemAttributes */
            $itemAttributes = [];

            foreach ($data->items as $item) {
                if ($item->quantity <= 0) {
                    throw ApiException::unprocessable('Item quantity must be greater than zero.', 'INVALID_QUANTITY');
                }
                if ($item->unitPriceCents < 0) {
                    throw ApiException::unprocessable('Item unit price cannot be negative.', 'INVALID_PRICE');
                }

                $lineSubtotal = $item->quantity * $item->unitPriceCents;
                $lineTax = (int) round($lineSubtotal * ($item->taxRatePercent / 100));
                $lineTotal = $lineSubtotal + $lineTax;

                $subtotalCents += $lineSubtotal;
                $taxCents += $lineTax;
                $totalCents += $lineTotal;

                $itemAttributes[] = [
                    'product_id' => $item->productId,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_price_cents' => $item->unitPriceCents,
                    'tax_rate_percent' => $item->taxRatePercent,
                    'tax_category' => $item->taxCategory,
                    'subtotal_cents' => $lineSubtotal,
                    'tax_cents' => $lineTax,
                    'total_cents' => $lineTotal,
                    'cogs_unit_cost_cents' => $item->cogsUnitCostCents,
                    'notes' => $item->notes,
                ];
            }

            $creditNote->update([
                'customer_id' => $data->customerId,
                'sales_invoice_id' => $data->salesInvoiceId,
                'warehouse_id' => $data->warehouseId,
                'issue_date' => $data->issueDate,
                'currency' => $data->currency,
                'subtotal_cents' => $subtotalCents,
                'tax_cents' => $taxCents,
                'total_cents' => $totalCents,
                'restock' => $data->restock,
                'reason' => $data->reason,
                'notes' => $data->notes,
            ]);

            $creditNote->items()->delete();
            foreach ($itemAttributes as $attrs) {
                $creditNote->items()->create($attrs);
            }

            return $creditNote->load('items');
        }, 3);
    }

    public function delete(string $id): void
    {
        $this->db->connection('tenant')->transaction(function () use ($id): void {
            /** @var CreditNote|null $creditNote */
            $creditNote = CreditNote::where('id', $id)->lockForUpdate()->first();

            if ($creditNote === null) {
                throw ApiException::notFound("Credit note '{$id}' not found.", 'CREDIT_NOTE_NOT_FOUND');
            }

            if ($creditNote->status->isImmutable()) {
                throw ApiException::unprocessable(
                    "Credit note is in immutable status '{$creditNote->status->value}' and cannot be deleted.",
                    'CREDIT_NOTE_IMMUTABLE'
                );
            }

            $creditNote->delete();
        }, 3);
    }

    public function post(string $id, string $userId): CreditNote
    {
        return $this->db->connection('tenant')->transaction(function () use ($id, $userId): CreditNote {
            /** @var CreditNote|null $creditNote */
            $creditNote = CreditNote::with('items')->where('id', $id)->lockForUpdate()->first();

            if ($creditNote === null) {
                throw ApiException::notFound("Credit note '{$id}' not found.", 'CREDIT_NOTE_NOT_FOUND');
            }

            if ($creditNote->status !== CreditNoteStatus::Draft) {
                throw ApiException::unprocessable(
                    "Credit note cannot be posted from '{$creditNote->status->value}' status.",
                    'INVALID_CREDIT_NOTE_STATUS'
                );
            }

            $totalCogsCents = 0;

            // Restock returned items if enabled
            if ($creditNote->restock) {
                foreach ($creditNote->items as $item) {
                    if ($item->quantity <= 0) {
                        continue;
                    }

                    $movement = $this->stockService->recordMovement(new RecordStockMovementData(
                        warehouseId: $creditNote->warehouse_id,
                        productId: $item->product_id,
                        type: StockMovementType::SalesReturn,
                        quantity: $item->quantity,
                        unitCostCents: $item->cogs_unit_cost_cents,
                        sourceType: 'credit_note',
                        sourceId: $creditNote->id,
                        userId: $userId,
                        notes: "Restock for Credit Note {$creditNote->credit_note_number}",
                    ));

                    $itemCogs = $movement->quantity * $movement->unit_cost_cents;
                    $item->update(['cogs_unit_cost_cents' => $movement->unit_cost_cents]);
                    $totalCogsCents += $itemCogs;
                }
            }

            // Reduce linked Sales Invoice amount_due_cents if linked
            if (! empty($creditNote->sales_invoice_id)) {
                /** @var SalesInvoice|null $invoice */
                $invoice = SalesInvoice::where('id', $creditNote->sales_invoice_id)->lockForUpdate()->first();
                if ($invoice !== null) {
                    $reduction = min($invoice->amount_due_cents, $creditNote->total_cents);
                    $newAmountDue = $invoice->amount_due_cents - $reduction;
                    $invoice->amount_due_cents = $newAmountDue;
                    if ($newAmountDue === 0 && $invoice->status !== SalesInvoiceStatus::Paid) {
                        $invoice->status = SalesInvoiceStatus::Paid;
                    }
                    $invoice->save();
                }
            }

            $creditNote->update([
                'status' => CreditNoteStatus::Posted,
                'posted_at' => now(),
            ]);

            // Post General Ledger double-entry
            $this->documentPostingService->postCreditNote(
                creditNoteId: $creditNote->id,
                creditNoteNumber: $creditNote->credit_note_number,
                issueDate: Carbon::parse($creditNote->issue_date),
                subtotalCents: $creditNote->subtotal_cents,
                taxCents: $creditNote->tax_cents,
                totalCents: $creditNote->total_cents,
                cogsCents: $totalCogsCents,
                currency: $creditNote->currency,
                userId: $userId,
            );

            $tenant = TenantContext::get();
            $tenantId = $tenant !== null ? (string) $tenant->id : '';

            CreditNotePosted::dispatch($creditNote, $tenantId, $userId);

            return $creditNote->load('items');
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, CreditNote>
     */
    public function list(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $query = CreditNote::with('items')->orderByDesc('created_at');

        if (! empty($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }

        if (! empty($filters['sales_invoice_id'])) {
            $query->where('sales_invoice_id', $filters['sales_invoice_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['search'])) {
            $search = (string) $filters['search'];
            $query->where('credit_note_number', 'ilike', "%{$search}%");
        }

        return $query->paginate($perPage);
    }

    public function findById(string $id): ?CreditNote
    {
        return CreditNote::with(['items', 'invoice'])->find($id);
    }

    /**
     * @return list<array{
     *     id: string,
     *     document_number: string,
     *     customer_id: string,
     *     sales_invoice_id: string,
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
     *         unit_price_cents: int,
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
        $creditNotes = CreditNote::with('items')
            ->where('status', CreditNoteStatus::Posted)
            ->whereDate('issue_date', '>=', $startDate->toDateString())
            ->whereDate('issue_date', '<=', $endDate->toDateString())
            ->orderBy('issue_date', 'asc')
            ->orderBy('credit_note_number', 'asc')
            ->get();

        $result = [];
        foreach ($creditNotes as $cn) {
            $items = [];
            foreach ($cn->items as $item) {
                $items[] = [
                    'id' => (string) $item->id,
                    'product_id' => (string) $item->product_id,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_price_cents' => $item->unit_price_cents,
                    'tax_rate_percent' => $item->tax_rate_percent,
                    'tax_category' => $item->tax_category,
                    'subtotal_cents' => $item->subtotal_cents,
                    'tax_cents' => $item->tax_cents,
                    'total_cents' => $item->total_cents,
                ];
            }

            $result[] = [
                'id' => (string) $cn->id,
                'document_number' => $cn->credit_note_number,
                'customer_id' => (string) $cn->customer_id,
                'sales_invoice_id' => (string) $cn->sales_invoice_id,
                'issue_date' => Carbon::parse($cn->issue_date)->toDateString(),
                'status' => $cn->status->value,
                'currency' => $cn->currency,
                'subtotal_cents' => $cn->subtotal_cents,
                'tax_cents' => $cn->tax_cents,
                'total_cents' => $cn->total_cents,
                'items' => $items,
            ];
        }

        return $result;
    }
}
