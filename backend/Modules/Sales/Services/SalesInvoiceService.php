<?php

declare(strict_types=1);

namespace Modules\Sales\Services;

use Carbon\Carbon;
use Illuminate\Database\DatabaseManager;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Sales\DTOs\CreateSalesInvoiceData;
use Modules\Sales\Enums\SalesInvoiceStatus;
use Modules\Sales\Events\SalesInvoicePosted;
use Modules\Sales\Models\SalesInvoice;
use Shared\Enums\DocumentType;
use Shared\Exceptions\ApiException;
use Shared\Services\DocumentNumberingService;
use Shared\Tenancy\TenantContext;

final class SalesInvoiceService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly DocumentNumberingService $numberingService,
    ) {}

    public function create(CreateSalesInvoiceData $data, ?string $userId = null): SalesInvoice
    {
        return $this->db->connection('tenant')->transaction(function () use ($data, $userId): SalesInvoice {
            if (empty($data->items)) {
                throw ApiException::unprocessable('At least one line item is required for a sales invoice.', 'ITEMS_REQUIRED');
            }

            $issueDate = Carbon::parse($data->issueDate);
            $year = (int) $issueDate->format('Y');

            $invoiceNumber = $this->numberingService->generateNext(
                type: DocumentType::Invoice,
                year: $year,
            );

            $subtotalCents = 0;
            $taxCents = 0;
            $totalCents = 0;

            /** @var list<array{product_id: string, description: ?string, quantity: int, unit_price_cents: int, tax_rate_percent: int, tax_category: ?string, subtotal_cents: int, tax_cents: int, total_cents: int, notes: ?string}> $itemAttributes */
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
                    'notes' => $item->notes,
                ];
            }

            /** @var SalesInvoice $invoice */
            $invoice = SalesInvoice::create([
                'invoice_number' => $invoiceNumber,
                'sales_quotation_id' => $data->quotationId,
                'customer_id' => $data->customerId,
                'status' => SalesInvoiceStatus::Draft,
                'issue_date' => $data->issueDate,
                'due_date' => $data->dueDate,
                'currency' => $data->currency,
                'subtotal_cents' => $subtotalCents,
                'tax_cents' => $taxCents,
                'total_cents' => $totalCents,
                'amount_paid_cents' => 0,
                'amount_due_cents' => $totalCents,
                'notes' => $data->notes,
                'created_by_user_id' => $userId,
            ]);

            foreach ($itemAttributes as $attrs) {
                $invoice->items()->create($attrs);
            }

            return $invoice->load('items');
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, SalesInvoice>
     */
    public function list(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $query = SalesInvoice::with('items')->orderByDesc('created_at');

        if (! empty($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['search'])) {
            $search = (string) $filters['search'];
            $query->where(function ($q) use ($search): void {
                $q->where('invoice_number', 'ilike', "%{$search}%")
                    ->orWhere('notes', 'ilike', "%{$search}%");
            });
        }

        return $query->paginate(max(1, min(100, $perPage)));
    }

    public function find(string $id): SalesInvoice
    {
        /** @var SalesInvoice|null $invoice */
        $invoice = SalesInvoice::with('items')->find($id);

        if ($invoice === null) {
            throw ApiException::notFound("Sales invoice '{$id}' not found.", 'INVOICE_NOT_FOUND');
        }

        return $invoice;
    }

    public function update(string $id, CreateSalesInvoiceData $data, string $userId): SalesInvoice
    {
        return $this->db->connection('tenant')->transaction(function () use ($id, $data): SalesInvoice {
            /** @var SalesInvoice|null $invoice */
            $invoice = SalesInvoice::where('id', $id)->lockForUpdate()->first();

            if ($invoice === null) {
                throw ApiException::notFound("Sales invoice '{$id}' not found.", 'INVOICE_NOT_FOUND');
            }

            if ($invoice->status->isImmutable()) {
                throw ApiException::unprocessable(
                    "Sales invoice is in immutable status '{$invoice->status->value}' and cannot be updated.",
                    'INVOICE_IMMUTABLE'
                );
            }

            if (empty($data->items)) {
                throw ApiException::unprocessable('At least one line item is required.', 'ITEMS_REQUIRED');
            }

            $subtotalCents = 0;
            $taxCents = 0;
            $totalCents = 0;

            /** @var list<array{product_id: string, description: ?string, quantity: int, unit_price_cents: int, tax_rate_percent: int, tax_category: ?string, subtotal_cents: int, tax_cents: int, total_cents: int, notes: ?string}> $itemAttributes */
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
                    'notes' => $item->notes,
                ];
            }

            $invoice->update([
                'customer_id' => $data->customerId,
                'issue_date' => $data->issueDate,
                'due_date' => $data->dueDate,
                'currency' => $data->currency,
                'subtotal_cents' => $subtotalCents,
                'tax_cents' => $taxCents,
                'total_cents' => $totalCents,
                'amount_due_cents' => $totalCents,
                'notes' => $data->notes,
            ]);

            $invoice->items()->delete();
            foreach ($itemAttributes as $attrs) {
                $invoice->items()->create($attrs);
            }

            return $invoice->load('items');
        }, 3);
    }

    public function delete(string $id): void
    {
        $this->db->connection('tenant')->transaction(function () use ($id): void {
            /** @var SalesInvoice|null $invoice */
            $invoice = SalesInvoice::where('id', $id)->lockForUpdate()->first();

            if ($invoice === null) {
                throw ApiException::notFound("Sales invoice '{$id}' not found.", 'INVOICE_NOT_FOUND');
            }

            if ($invoice->status->isImmutable()) {
                throw ApiException::unprocessable(
                    "Sales invoice is in immutable status '{$invoice->status->value}' and cannot be deleted.",
                    'INVOICE_IMMUTABLE'
                );
            }

            $invoice->delete();
        }, 3);
    }

    public function post(string $id, string $userId): SalesInvoice
    {
        return $this->db->connection('tenant')->transaction(function () use ($id, $userId): SalesInvoice {
            /** @var SalesInvoice|null $invoice */
            $invoice = SalesInvoice::with('items')->where('id', $id)->lockForUpdate()->first();

            if ($invoice === null) {
                throw ApiException::notFound("Sales invoice '{$id}' not found.", 'INVOICE_NOT_FOUND');
            }

            if ($invoice->status !== SalesInvoiceStatus::Draft) {
                throw ApiException::unprocessable(
                    "Sales invoice cannot be posted from '{$invoice->status->value}' status.",
                    'INVALID_INVOICE_STATUS'
                );
            }

            $invoice->update([
                'status' => SalesInvoiceStatus::Posted,
                'posted_at' => now(),
                'posted_by_user_id' => $userId,
                'amount_due_cents' => $invoice->total_cents,
                'amount_paid_cents' => 0,
            ]);

            $tenant = TenantContext::get();
            $tenantId = $tenant !== null ? (string) $tenant->id : '';

            SalesInvoicePosted::dispatch($invoice, $tenantId, $userId);

            return $invoice->load('items');
        }, 3);
    }

    public function getOutstandingReceivableCents(string $customerId): int
    {
        return (int) SalesInvoice::where('customer_id', $customerId)
            ->whereIn('status', [SalesInvoiceStatus::Posted, SalesInvoiceStatus::PartiallyPaid])
            ->sum('amount_due_cents');
    }

    public function recordPayment(string $id, int $amountCents): SalesInvoice
    {
        return $this->db->connection('tenant')->transaction(function () use ($id, $amountCents): SalesInvoice {
            /** @var SalesInvoice|null $invoice */
            $invoice = SalesInvoice::where('id', $id)->lockForUpdate()->first();

            if ($invoice === null) {
                throw ApiException::notFound("Sales invoice '{$id}' not found.", 'INVOICE_NOT_FOUND');
            }

            if (! in_array($invoice->status, [SalesInvoiceStatus::Posted, SalesInvoiceStatus::PartiallyPaid], true)) {
                throw ApiException::unprocessable(
                    "Payment cannot be recorded for invoice in status '{$invoice->status->value}'.",
                    'INVALID_INVOICE_STATUS'
                );
            }

            if ($amountCents <= 0) {
                throw ApiException::unprocessable('Payment amount must be greater than zero.', 'INVALID_PAYMENT_AMOUNT');
            }

            $newPaid = $invoice->amount_paid_cents + $amountCents;
            $newDue = max(0, $invoice->total_cents - $newPaid);
            $newStatus = $newDue === 0 ? SalesInvoiceStatus::Paid : SalesInvoiceStatus::PartiallyPaid;

            $invoice->update([
                'amount_paid_cents' => $newPaid,
                'amount_due_cents' => $newDue,
                'status' => $newStatus,
            ]);

            return $invoice->load('items');
        }, 3);
    }
}
