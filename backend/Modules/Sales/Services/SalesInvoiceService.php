<?php

declare(strict_types=1);

namespace Modules\Sales\Services;

use Carbon\Carbon;
use Illuminate\Database\DatabaseManager;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Accounting\Services\DocumentPostingService;
use Modules\Contacts\Services\ContactService;
use Modules\Inventory\DTOs\RecordStockMovementData;
use Modules\Inventory\Enums\StockMovementType;
use Modules\Inventory\Services\ProductService;
use Modules\Inventory\Services\StockService;
use Modules\Inventory\Services\WarehouseService;
use Modules\Sales\DTOs\CreateSalesInvoiceData;
use Modules\Sales\Enums\CreditNoteStatus;
use Modules\Sales\Enums\SalesInvoiceStatus;
use Modules\Sales\Events\SalesInvoicePosted;
use Modules\Sales\Events\SalesInvoicePostedToLedger;
use Modules\Sales\Events\SalesInvoiceVoided;
use Modules\Sales\Models\CreditNote;
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
        private readonly StockService $stockService,
        private readonly WarehouseService $warehouseService,
        private readonly ContactService $contactService,
        private readonly ProductService $productService,
        private readonly DocumentPostingService $documentPostingService,
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
                'warehouse_id' => $data->warehouseId,
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
                'warehouse_id' => $data->warehouseId ?? $invoice->warehouse_id,
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

            // Determine fulfillment warehouse: specified warehouse, or default active warehouse
            $warehouseId = $invoice->warehouse_id;
            if (empty($warehouseId)) {
                $defaultWarehouse = $this->warehouseService->getDefault();
                if ($defaultWarehouse === null) {
                    throw ApiException::unprocessable(
                        'No active warehouse found to fulfill stock delivery for this invoice.',
                        'NO_ACTIVE_WAREHOUSE'
                    );
                }
                $warehouseId = (string) $defaultWarehouse->id;
                $invoice->warehouse_id = $warehouseId;
            }

            $cogsCents = 0;
            $lineDetails = [];

            // Decrement inventory stock atomically for each line item
            foreach ($invoice->items as $item) {
                if ($item->quantity <= 0) {
                    continue;
                }

                $movement = $this->stockService->recordMovement(new RecordStockMovementData(
                    warehouseId: $warehouseId,
                    productId: $item->product_id,
                    type: StockMovementType::SalesDelivery,
                    quantity: $item->quantity,
                    unitCostCents: 0,
                    sourceType: 'sales_invoice',
                    sourceId: $invoice->id,
                    userId: $userId,
                    notes: "Delivery for Sales Invoice {$invoice->invoice_number}",
                ));

                $cogsCents += $item->quantity * $movement->unit_cost_cents;

                $lineDetails[] = [
                    'product_id' => $item->product_id,
                    'quantity' => $item->quantity,
                    'unit_price_cents' => $item->unit_price_cents,
                    'subtotal_cents' => $item->subtotal_cents,
                    'tax_cents' => $item->tax_cents,
                    'total_cents' => $item->total_cents,
                ];
            }

            $invoice->update([
                'status' => SalesInvoiceStatus::Posted,
                'warehouse_id' => $warehouseId,
                'posted_at' => now(),
                'posted_by_user_id' => $userId,
                'amount_due_cents' => $invoice->total_cents,
                'amount_paid_cents' => 0,
            ]);

            // Post journal entry to General Ledger atomically inside the transaction
            $this->documentPostingService->postSalesInvoice(
                invoiceId: $invoice->id,
                invoiceNumber: $invoice->invoice_number,
                issueDate: Carbon::parse($invoice->issue_date),
                subtotalCents: $invoice->subtotal_cents,
                taxCents: $invoice->tax_cents,
                totalCents: $invoice->total_cents,
                cogsCents: $cogsCents,
                currency: $invoice->currency,
                userId: $userId,
            );

            $tenant = TenantContext::get();
            $tenantId = $tenant !== null ? (string) $tenant->id : '';

            SalesInvoicePosted::dispatch($invoice, $tenantId, $userId);
            SalesInvoicePostedToLedger::dispatch($invoice, $tenantId, $userId, $cogsCents, $lineDetails);

            return $invoice->load('items');
        }, 3);
    }

    public function void(string $id, string $userId): SalesInvoice
    {
        return $this->db->connection('tenant')->transaction(function () use ($id, $userId): SalesInvoice {
            /** @var SalesInvoice|null $invoice */
            $invoice = SalesInvoice::with('items')->where('id', $id)->lockForUpdate()->first();

            if ($invoice === null) {
                throw ApiException::notFound("Sales invoice '{$id}' not found.", 'INVOICE_NOT_FOUND');
            }

            if ($invoice->amount_paid_cents > 0) {
                throw ApiException::unprocessable(
                    'Cannot void sales invoice with recorded payments. Settle or reverse payments before voiding.',
                    'INVOICE_HAS_PAYMENTS'
                );
            }

            if ($invoice->status !== SalesInvoiceStatus::Posted) {
                throw ApiException::unprocessable(
                    "Only posted sales invoices can be voided. Current status is '{$invoice->status->value}'.",
                    'INVALID_INVOICE_STATUS'
                );
            }

            $warehouseId = $invoice->warehouse_id;
            if (empty($warehouseId)) {
                $defaultWarehouse = $this->warehouseService->getDefault();
                $warehouseId = $defaultWarehouse !== null ? (string) $defaultWarehouse->id : null;
            }

            // Restore inventory stock atomically for each line item
            if (! empty($warehouseId)) {
                foreach ($invoice->items as $item) {
                    if ($item->quantity <= 0) {
                        continue;
                    }

                    $this->stockService->recordMovement(new RecordStockMovementData(
                        warehouseId: $warehouseId,
                        productId: $item->product_id,
                        type: StockMovementType::AdjustmentIn,
                        quantity: $item->quantity,
                        unitCostCents: 0,
                        sourceType: 'sales_invoice_void',
                        sourceId: $invoice->id,
                        userId: $userId,
                        notes: "Stock return on voiding Sales Invoice {$invoice->invoice_number}",
                    ));
                }
            }

            $invoice->update([
                'status' => SalesInvoiceStatus::Void,
                'voided_at' => now(),
                'amount_due_cents' => 0,
            ]);

            // Reverse linked journal entry in General Ledger atomically inside the transaction
            $this->documentPostingService->reverseSalesInvoice(
                invoiceId: $invoice->id,
                reason: 'Sales invoice voided',
                userId: $userId,
            );

            $tenant = TenantContext::get();
            $tenantId = $tenant !== null ? (string) $tenant->id : '';

            SalesInvoiceVoided::dispatch($invoice, $tenantId, $userId, true);

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

    /**
     * @return array{
     *     invoice: SalesInvoice,
     *     customer: array{id: string, code: string, name: string, type: string, email: ?string, phone: ?string, tax_id: ?string, billing_address: ?array<string, mixed>, shipping_address: ?array<string, mixed>, payment_terms_days: int}|null,
     *     company: array{name: string, tax_id: ?string, address: ?string, email: ?string, phone: ?string, bank_details: ?string, currency: string},
     *     items: list<array{product_name: string, sku: string, description: ?string, quantity: int, unit_price_cents: int, tax_rate_percent: int, tax_cents: int, total_cents: int}>
     * }
     */
    public function getPdfData(string $id): array
    {
        $invoice = $this->find($id);
        $customer = $this->contactService->getDetails($invoice->customer_id);

        $tenant = TenantContext::get();
        /** @var array<string, string> $settings */
        $settings = $this->db->connection('tenant')
            ->table('settings')
            ->pluck('value', 'key')
            ->all();

        $company = [
            'name' => $settings['company_name'] ?? ($tenant !== null ? ucfirst($tenant->slug) : 'NexusERP Company'),
            'tax_id' => $settings['company_tax_id'] ?? $settings['tax_number'] ?? null,
            'address' => $settings['company_address'] ?? null,
            'email' => $settings['company_email'] ?? null,
            'phone' => $settings['company_phone'] ?? null,
            'bank_details' => $settings['bank_details'] ?? null,
            'currency' => $invoice->currency,
        ];

        $items = [];
        foreach ($invoice->items as $item) {
            $product = $this->productService->getDetails($item->product_id);
            $items[] = [
                'product_name' => $product !== null ? $product['name'] : 'Product',
                'sku' => $product !== null ? $product['sku'] : $item->product_id,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit_price_cents' => $item->unit_price_cents,
                'tax_rate_percent' => $item->tax_rate_percent,
                'tax_cents' => $item->tax_cents,
                'total_cents' => $item->total_cents,
            ];
        }

        return [
            'invoice' => $invoice,
            'customer' => $customer,
            'company' => $company,
            'items' => $items,
        ];
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
     *     invoices: list<array{
     *         id: string,
     *         invoice_number: string,
     *         issue_date: string,
     *         due_date: string,
     *         total_cents: int,
     *         amount_paid_cents: int,
     *         amount_due_cents: int,
     *         days_overdue: int,
     *         bucket: 'current'|'days_1_30'|'days_31_60'|'days_61_90'|'days_over_90'
     *     }>
     * }
     */
    public function getAgingBuckets(string $customerId, ?Carbon $asOfDate = null): array
    {
        $asOf = ($asOfDate ?? Carbon::today())->startOfDay();

        $invoices = SalesInvoice::where('customer_id', $customerId)
            ->whereIn('status', [SalesInvoiceStatus::Posted, SalesInvoiceStatus::PartiallyPaid])
            ->where('amount_due_cents', '>', 0)
            ->orderBy('due_date', 'asc')
            ->get();

        $currentCents = 0;
        $days130Cents = 0;
        $days3160Cents = 0;
        $days6190Cents = 0;
        $daysOver90Cents = 0;
        $invoicesList = [];

        foreach ($invoices as $invoice) {
            $dueDate = Carbon::parse($invoice->due_date)->startOfDay();
            $daysOverdue = (int) $dueDate->diffInDays($asOf, false);

            if ($daysOverdue <= 0) {
                $bucket = 'current';
                $currentCents += $invoice->amount_due_cents;
            } elseif ($daysOverdue <= 30) {
                $bucket = 'days_1_30';
                $days130Cents += $invoice->amount_due_cents;
            } elseif ($daysOverdue <= 60) {
                $bucket = 'days_31_60';
                $days3160Cents += $invoice->amount_due_cents;
            } elseif ($daysOverdue <= 90) {
                $bucket = 'days_61_90';
                $days6190Cents += $invoice->amount_due_cents;
            } else {
                $bucket = 'days_over_90';
                $daysOver90Cents += $invoice->amount_due_cents;
            }

            $invoicesList[] = [
                'id' => (string) $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'issue_date' => Carbon::parse($invoice->issue_date)->toDateString(),
                'due_date' => $dueDate->toDateString(),
                'total_cents' => $invoice->total_cents,
                'amount_paid_cents' => $invoice->amount_paid_cents,
                'amount_due_cents' => $invoice->amount_due_cents,
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
            'invoices' => $invoicesList,
        ];
    }

    /**
     * @return array{
     *     prior_invoiced_cents: int,
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
    public function getStatementTransactions(string $customerId, ?Carbon $startDate = null, ?Carbon $endDate = null): array
    {
        $validStatuses = [
            SalesInvoiceStatus::Posted,
            SalesInvoiceStatus::PartiallyPaid,
            SalesInvoiceStatus::Paid,
        ];

        $priorInvoicedCents = 0;
        if ($startDate !== null) {
            $priorInvoicedCents = (int) SalesInvoice::where('customer_id', $customerId)
                ->whereIn('status', $validStatuses)
                ->whereDate('issue_date', '<', $startDate->toDateString())
                ->sum('total_cents');

            $priorCreditedCents = (int) CreditNote::where('customer_id', $customerId)
                ->where('status', CreditNoteStatus::Posted)
                ->whereDate('issue_date', '<', $startDate->toDateString())
                ->sum('total_cents');

            $priorInvoicedCents -= $priorCreditedCents;
        }

        $query = SalesInvoice::where('customer_id', $customerId)
            ->whereIn('status', $validStatuses);

        if ($startDate !== null) {
            $query->whereDate('issue_date', '>=', $startDate->toDateString());
        }

        if ($endDate !== null) {
            $query->whereDate('issue_date', '<=', $endDate->toDateString());
        }

        $invoices = $query->orderBy('issue_date', 'asc')->orderBy('invoice_number', 'asc')->get();

        $transactions = [];
        foreach ($invoices as $invoice) {
            $transactions[] = [
                'id' => (string) $invoice->id,
                'date' => Carbon::parse($invoice->issue_date)->toDateString(),
                'due_date' => Carbon::parse($invoice->due_date)->toDateString(),
                'document_number' => $invoice->invoice_number,
                'type' => 'invoice',
                'description' => "Sales Invoice {$invoice->invoice_number}",
                'debit_cents' => $invoice->total_cents,
                'credit_cents' => 0,
                'amount_due_cents' => $invoice->amount_due_cents,
                'status' => $invoice->status->value,
            ];
        }

        // Include posted credit notes
        $cnQuery = CreditNote::where('customer_id', $customerId)
            ->where('status', CreditNoteStatus::Posted);

        if ($startDate !== null) {
            $cnQuery->whereDate('issue_date', '>=', $startDate->toDateString());
        }

        if ($endDate !== null) {
            $cnQuery->whereDate('issue_date', '<=', $endDate->toDateString());
        }

        $creditNotes = $cnQuery->orderBy('issue_date', 'asc')->orderBy('credit_note_number', 'asc')->get();
        foreach ($creditNotes as $cn) {
            $transactions[] = [
                'id' => (string) $cn->id,
                'date' => Carbon::parse($cn->issue_date)->toDateString(),
                'due_date' => null,
                'document_number' => $cn->credit_note_number,
                'type' => 'credit_note',
                'description' => "Credit Note {$cn->credit_note_number}",
                'debit_cents' => 0,
                'credit_cents' => $cn->total_cents,
                'amount_due_cents' => 0,
                'status' => $cn->status->value,
            ];
        }

        return [
            'prior_invoiced_cents' => $priorInvoicedCents,
            'transactions' => $transactions,
        ];
    }

    /**
     * @return list<array{
     *     id: string,
     *     document_number: string,
     *     customer_id: string,
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
        $validStatuses = [
            SalesInvoiceStatus::Posted,
            SalesInvoiceStatus::PartiallyPaid,
            SalesInvoiceStatus::Paid,
        ];

        $invoices = SalesInvoice::with('items')
            ->whereIn('status', $validStatuses)
            ->whereDate('issue_date', '>=', $startDate->toDateString())
            ->whereDate('issue_date', '<=', $endDate->toDateString())
            ->orderBy('issue_date', 'asc')
            ->orderBy('invoice_number', 'asc')
            ->get();

        $result = [];
        foreach ($invoices as $invoice) {
            $items = [];
            foreach ($invoice->items as $item) {
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
                'id' => (string) $invoice->id,
                'document_number' => $invoice->invoice_number,
                'customer_id' => (string) $invoice->customer_id,
                'issue_date' => Carbon::parse($invoice->issue_date)->toDateString(),
                'status' => $invoice->status->value,
                'currency' => $invoice->currency,
                'subtotal_cents' => $invoice->subtotal_cents,
                'tax_cents' => $invoice->tax_cents,
                'total_cents' => $invoice->total_cents,
                'items' => $items,
            ];
        }

        return $result;
    }

    public function countCurrentMonthInvoices(): int
    {
        return SalesInvoice::query()
            ->whereBetween('created_at', [
                now()->startOfMonth(),
                now()->endOfMonth(),
            ])
            ->count();
    }
}
