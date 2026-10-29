<?php

declare(strict_types=1);

namespace Modules\Sales\Services;

use Carbon\Carbon;
use Illuminate\Database\DatabaseManager;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Contacts\Services\ContactService;
use Modules\Inventory\Services\ProductService;
use Modules\Sales\DTOs\CreateSalesInvoiceData;
use Modules\Sales\DTOs\CreateSalesQuotationData;
use Modules\Sales\DTOs\SalesInvoiceItemData;
use Modules\Sales\Enums\SalesQuotationStatus;
use Modules\Sales\Events\SalesQuotationSent;
use Modules\Sales\Models\SalesInvoice;
use Modules\Sales\Models\SalesQuotation;
use Shared\Enums\DocumentType;
use Shared\Exceptions\ApiException;
use Shared\Services\DocumentNumberingService;
use Shared\Tenancy\TenantContext;

final class SalesQuotationService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly DocumentNumberingService $numberingService,
        private readonly SalesInvoiceService $invoiceService,
        private readonly ContactService $contactService,
        private readonly ProductService $productService,
    ) {}

    public function create(CreateSalesQuotationData $data, ?string $userId = null): SalesQuotation
    {
        return $this->db->connection('tenant')->transaction(function () use ($data, $userId): SalesQuotation {
            if (empty($data->items)) {
                throw ApiException::unprocessable('At least one line item is required for a sales quotation.', 'ITEMS_REQUIRED');
            }

            $issueDate = Carbon::parse($data->issueDate);
            $year = (int) $issueDate->format('Y');

            $quotationNumber = $this->numberingService->generateNext(
                type: DocumentType::Quotation,
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

            /** @var SalesQuotation $quotation */
            $quotation = SalesQuotation::create([
                'quotation_number' => $quotationNumber,
                'customer_id' => $data->customerId,
                'warehouse_id' => $data->warehouseId,
                'status' => SalesQuotationStatus::Draft,
                'issue_date' => $data->issueDate,
                'expiry_date' => $data->expiryDate,
                'currency' => $data->currency,
                'subtotal_cents' => $subtotalCents,
                'tax_cents' => $taxCents,
                'total_cents' => $totalCents,
                'notes' => $data->notes,
                'created_by_user_id' => $userId,
            ]);

            foreach ($itemAttributes as $attrs) {
                $quotation->items()->create($attrs);
            }

            return $quotation->load('items');
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, SalesQuotation>
     */
    public function list(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $query = SalesQuotation::with('items')->orderByDesc('created_at');

        if (! empty($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['search'])) {
            $search = (string) $filters['search'];
            $query->where(function ($q) use ($search): void {
                $q->where('quotation_number', 'ilike', "%{$search}%")
                    ->orWhere('notes', 'ilike', "%{$search}%");
            });
        }

        return $query->paginate(max(1, min(100, $perPage)));
    }

    public function find(string $id): SalesQuotation
    {
        /** @var SalesQuotation|null $quotation */
        $quotation = SalesQuotation::with('items')->find($id);

        if ($quotation === null) {
            throw ApiException::notFound("Sales quotation '{$id}' not found.", 'QUOTATION_NOT_FOUND');
        }

        return $quotation;
    }

    public function markAsSent(string $id, string $userId): SalesQuotation
    {
        return $this->db->connection('tenant')->transaction(function () use ($id, $userId): SalesQuotation {
            /** @var SalesQuotation|null $quotation */
            $quotation = SalesQuotation::where('id', $id)->lockForUpdate()->first();

            if ($quotation === null) {
                throw ApiException::notFound("Sales quotation '{$id}' not found.", 'QUOTATION_NOT_FOUND');
            }

            if ($quotation->status !== SalesQuotationStatus::Draft) {
                throw ApiException::unprocessable(
                    "Quotation cannot be marked as sent from '{$quotation->status->value}' status.",
                    'INVALID_QUOTATION_STATUS'
                );
            }

            $quotation->update([
                'status' => SalesQuotationStatus::Sent,
                'sent_at' => now(),
            ]);

            $tenant = TenantContext::get();
            $tenantId = $tenant !== null ? (string) $tenant->id : '';

            SalesQuotationSent::dispatch($quotation, $tenantId, $userId);

            return $quotation->load('items');
        }, 3);
    }

    public function markAsAccepted(string $id, string $userId): SalesQuotation
    {
        return $this->db->connection('tenant')->transaction(function () use ($id): SalesQuotation {
            /** @var SalesQuotation|null $quotation */
            $quotation = SalesQuotation::where('id', $id)->lockForUpdate()->first();

            if ($quotation === null) {
                throw ApiException::notFound("Sales quotation '{$id}' not found.", 'QUOTATION_NOT_FOUND');
            }

            if (! in_array($quotation->status, [SalesQuotationStatus::Draft, SalesQuotationStatus::Sent], true)) {
                throw ApiException::unprocessable(
                    "Quotation cannot be accepted from '{$quotation->status->value}' status.",
                    'INVALID_QUOTATION_STATUS'
                );
            }

            $quotation->update([
                'status' => SalesQuotationStatus::Accepted,
                'accepted_at' => now(),
            ]);

            return $quotation->load('items');
        }, 3);
    }

    public function markAsRejected(string $id, string $userId): SalesQuotation
    {
        return $this->db->connection('tenant')->transaction(function () use ($id): SalesQuotation {
            /** @var SalesQuotation|null $quotation */
            $quotation = SalesQuotation::where('id', $id)->lockForUpdate()->first();

            if ($quotation === null) {
                throw ApiException::notFound("Sales quotation '{$id}' not found.", 'QUOTATION_NOT_FOUND');
            }

            if (! in_array($quotation->status, [SalesQuotationStatus::Draft, SalesQuotationStatus::Sent], true)) {
                throw ApiException::unprocessable(
                    "Quotation cannot be rejected from '{$quotation->status->value}' status.",
                    'INVALID_QUOTATION_STATUS'
                );
            }

            $quotation->update([
                'status' => SalesQuotationStatus::Rejected,
                'rejected_at' => now(),
            ]);

            return $quotation->load('items');
        }, 3);
    }

    public function convertToInvoice(
        string $id,
        string $userId,
        ?string $dueDate = null,
        bool $postImmediately = false,
        ?string $warehouseId = null,
    ): SalesInvoice {
        return $this->db->connection('tenant')->transaction(function () use ($id, $userId, $dueDate, $postImmediately, $warehouseId): SalesInvoice {
            /** @var SalesQuotation|null $quotation */
            $quotation = SalesQuotation::with('items')->where('id', $id)->lockForUpdate()->first();

            if ($quotation === null) {
                throw ApiException::notFound("Sales quotation '{$id}' not found.", 'QUOTATION_NOT_FOUND');
            }

            if ($quotation->converted_to_invoice_id !== null) {
                throw ApiException::unprocessable(
                    "Quotation has already been converted to invoice '{$quotation->converted_to_invoice_id}'.",
                    'QUOTATION_ALREADY_CONVERTED'
                );
            }

            if ($quotation->status !== SalesQuotationStatus::Accepted) {
                throw ApiException::unprocessable(
                    "Only accepted quotations can be converted to invoices. Current status is '{$quotation->status->value}'.",
                    'QUOTATION_NOT_ACCEPTED'
                );
            }

            $calculatedDueDate = $dueDate ?? now()->addDays(30)->toDateString();
            $finalWarehouseId = $warehouseId ?? $quotation->warehouse_id;

            /** @var list<SalesInvoiceItemData> $invoiceItems */
            $invoiceItems = [];
            foreach ($quotation->items as $item) {
                $invoiceItems[] = new SalesInvoiceItemData(
                    productId: $item->product_id,
                    quantity: $item->quantity,
                    unitPriceCents: $item->unit_price_cents,
                    taxRatePercent: $item->tax_rate_percent,
                    taxCategory: $item->tax_category,
                    description: $item->description,
                    notes: $item->notes,
                );
            }

            $invoiceData = new CreateSalesInvoiceData(
                customerId: $quotation->customer_id,
                issueDate: now()->toDateString(),
                dueDate: $calculatedDueDate,
                currency: $quotation->currency,
                quotationId: $quotation->id,
                warehouseId: $finalWarehouseId,
                notes: $quotation->notes,
                items: $invoiceItems,
            );

            $invoice = $this->invoiceService->create($invoiceData, $userId);

            $quotation->update([
                'converted_to_invoice_id' => $invoice->id,
                'converted_at' => now(),
            ]);

            if ($postImmediately) {
                return $this->invoiceService->post($invoice->id, $userId);
            }

            return $invoice;
        }, 3);
    }

    /**
     * @return array{
     *     quotation: SalesQuotation,
     *     customer: array{id: string, code: string, name: string, type: string, email: ?string, phone: ?string, tax_id: ?string, billing_address: ?array<string, mixed>, shipping_address: ?array<string, mixed>, payment_terms_days: int}|null,
     *     company: array{name: string, tax_id: ?string, address: ?string, email: ?string, phone: ?string, currency: string},
     *     items: list<array{product_name: string, sku: string, description: ?string, quantity: int, unit_price_cents: int, tax_rate_percent: int, tax_cents: int, total_cents: int}>
     * }
     */
    public function getPdfData(string $id): array
    {
        $quotation = $this->find($id);
        $customer = $this->contactService->getDetails($quotation->customer_id);

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
            'currency' => $quotation->currency,
        ];

        $items = [];
        foreach ($quotation->items as $item) {
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
            'quotation' => $quotation,
            'customer' => $customer,
            'company' => $company,
            'items' => $items,
        ];
    }
}
