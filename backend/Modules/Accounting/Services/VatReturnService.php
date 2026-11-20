<?php

declare(strict_types=1);

namespace Modules\Accounting\Services;

use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\DatabaseManager;
use Modules\Accounting\DTOs\CalculateVatReturnData;
use Modules\Accounting\DTOs\FileVatReturnData;
use Modules\Accounting\Enums\JournalEntryStatus;
use Modules\Accounting\Enums\SystemAccount;
use Modules\Accounting\Enums\VatReturnStatus;
use Modules\Accounting\Models\VatReturn;
use Modules\Contacts\Services\ContactService;
use Modules\Purchasing\Services\DebitNoteService;
use Modules\Purchasing\Services\SupplierBillService;
use Modules\Sales\Services\CreditNoteService;
use Modules\Sales\Services\SalesInvoiceService;
use Shared\Enums\DocumentType;
use Shared\Exceptions\ApiException;
use Shared\Services\DocumentNumberingService;
use Shared\Services\PdfRendererService;
use Shared\Tenancy\TenantContext;

final class VatReturnService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly SalesInvoiceService $invoiceService,
        private readonly CreditNoteService $creditNoteService,
        private readonly SupplierBillService $billService,
        private readonly DebitNoteService $debitNoteService,
        private readonly ContactService $contactService,
        private readonly AccountService $accountService,
        private readonly DocumentNumberingService $documentNumbering,
        private readonly FiscalPeriodService $fiscalPeriodService,
        private readonly PdfRendererService $pdfRenderer,
    ) {}

    /**
     * @return array{
     *     from_date: string,
     *     to_date: string,
     *     fiscal_period: array{id: string, name: string, fiscal_year: int, period_number: int, status: string}|null,
     *     summary: array{
     *         total_sales_taxable_cents: int,
     *         total_output_vat_cents: int,
     *         total_sales_gross_cents: int,
     *         total_purchases_taxable_cents: int,
     *         total_input_vat_cents: int,
     *         total_purchases_gross_cents: int,
     *         net_vat_payable_cents: int
     *     },
     *     rate_breakdown: list<array{
     *         tax_rate_percent: int,
     *         sales_taxable_cents: int,
     *         output_vat_cents: int,
     *         sales_total_cents: int,
     *         purchases_taxable_cents: int,
     *         input_vat_cents: int,
     *         purchases_total_cents: int,
     *         net_vat_cents: int
     *     }>,
     *     ledger_reconciliation: array{
     *         gl_output_vat_cents: int,
     *         gl_input_vat_cents: int,
     *         gl_net_vat_cents: int,
     *         output_vat_discrepancy_cents: int,
     *         input_vat_discrepancy_cents: int,
     *         net_vat_discrepancy_cents: int,
     *         is_reconciled: bool
     *     },
     *     documents: list<array{
     *         document_type: 'sales_invoice'|'credit_note'|'supplier_bill'|'debit_note',
     *         id: string,
     *         document_number: string,
     *         date: string,
     *         contact_id: string,
     *         contact_name: string,
     *         contact_tax_id: ?string,
     *         direction: 'output'|'input',
     *         subtotal_cents: int,
     *         tax_cents: int,
     *         total_cents: int,
     *         rates: list<array{rate_percent: int, subtotal_cents: int, tax_cents: int}>
     *     }>
     * }
     */
    public function calculate(CalculateVatReturnData $data): array
    {
        $periodMeta = null;
        if ($data->periodId !== null) {
            $period = $this->fiscalPeriodService->findById($data->periodId);
            if ($period === null) {
                throw ApiException::notFound("Fiscal period '{$data->periodId}' not found.", 'PERIOD_NOT_FOUND');
            }
            $fromDate = Carbon::parse($period->start_date)->startOfDay();
            $toDate = Carbon::parse($period->end_date)->endOfDay();
            $periodMeta = [
                'id' => (string) $period->id,
                'name' => $period->name,
                'fiscal_year' => $period->fiscal_year,
                'period_number' => $period->period_number,
                'status' => $period->status->value,
            ];
        } else {
            $fromDate = $data->fromDate->copy()->startOfDay();
            $toDate = $data->toDate->copy()->endOfDay();
        }

        // Fetch participating transactions
        $invoices = $this->invoiceService->getVatReportData($fromDate, $toDate);
        $creditNotes = $this->creditNoteService->getVatReportData($fromDate, $toDate);
        $bills = $this->billService->getVatReportData($fromDate, $toDate);
        $debitNotes = $this->debitNoteService->getVatReportData($fromDate, $toDate);

        // Contact cache for fast lookup
        /** @var array<string, array{name: string, tax_id: ?string}> $contactsCache */
        $contactsCache = [];
        $resolveContact = function (string $contactId) use (&$contactsCache): array {
            if (! isset($contactsCache[$contactId])) {
                $details = $this->contactService->getDetails($contactId);
                $contactsCache[$contactId] = [
                    'name' => $details !== null ? $details['name'] : 'Unknown Contact',
                    'tax_id' => $details !== null ? $details['tax_id'] : null,
                ];
            }

            return $contactsCache[$contactId];
        };

        // Aggregation buckets per rate
        /** @var array<int, array{tax_rate_percent: int, sales_taxable_cents: int, output_vat_cents: int, purchases_taxable_cents: int, input_vat_cents: int}> $rateBuckets */
        $rateBuckets = [];

        /** @var list<array{document_type: 'sales_invoice'|'credit_note'|'supplier_bill'|'debit_note', id: string, document_number: string, date: string, contact_id: string, contact_name: string, contact_tax_id: ?string, direction: 'output'|'input', subtotal_cents: int, tax_cents: int, total_cents: int, rates: list<array{rate_percent: int, subtotal_cents: int, tax_cents: int}>}> $documents */
        $documents = [];

        // 1. Process Sales Invoices (Output VAT +)
        foreach ($invoices as $inv) {
            $contact = $resolveContact($inv['customer_id']);
            /** @var array<int, array{rate_percent: int, subtotal_cents: int, tax_cents: int}> $docRates */
            $docRates = [];

            foreach ($inv['items'] as $item) {
                $rate = $item['tax_rate_percent'];
                if (! isset($rateBuckets[$rate])) {
                    $rateBuckets[$rate] = [
                        'tax_rate_percent' => $rate,
                        'sales_taxable_cents' => 0,
                        'output_vat_cents' => 0,
                        'purchases_taxable_cents' => 0,
                        'input_vat_cents' => 0,
                    ];
                }
                $rateBuckets[$rate]['sales_taxable_cents'] += $item['subtotal_cents'];
                $rateBuckets[$rate]['output_vat_cents'] += $item['tax_cents'];

                if (! isset($docRates[$rate])) {
                    $docRates[$rate] = ['rate_percent' => $rate, 'subtotal_cents' => 0, 'tax_cents' => 0];
                }
                $docRates[$rate]['subtotal_cents'] += $item['subtotal_cents'];
                $docRates[$rate]['tax_cents'] += $item['tax_cents'];
            }

            $documents[] = [
                'document_type' => 'sales_invoice',
                'id' => $inv['id'],
                'document_number' => $inv['document_number'],
                'date' => $inv['issue_date'],
                'contact_id' => $inv['customer_id'],
                'contact_name' => $contact['name'],
                'contact_tax_id' => $contact['tax_id'],
                'direction' => 'output',
                'subtotal_cents' => $inv['subtotal_cents'],
                'tax_cents' => $inv['tax_cents'],
                'total_cents' => $inv['total_cents'],
                'rates' => array_values($docRates),
            ];
        }

        // 2. Process Sales Credit Notes (Output VAT -)
        foreach ($creditNotes as $cn) {
            $contact = $resolveContact($cn['customer_id']);
            /** @var array<int, array{rate_percent: int, subtotal_cents: int, tax_cents: int}> $docRates */
            $docRates = [];

            foreach ($cn['items'] as $item) {
                $rate = $item['tax_rate_percent'];
                if (! isset($rateBuckets[$rate])) {
                    $rateBuckets[$rate] = [
                        'tax_rate_percent' => $rate,
                        'sales_taxable_cents' => 0,
                        'output_vat_cents' => 0,
                        'purchases_taxable_cents' => 0,
                        'input_vat_cents' => 0,
                    ];
                }
                $rateBuckets[$rate]['sales_taxable_cents'] -= $item['subtotal_cents'];
                $rateBuckets[$rate]['output_vat_cents'] -= $item['tax_cents'];

                if (! isset($docRates[$rate])) {
                    $docRates[$rate] = ['rate_percent' => $rate, 'subtotal_cents' => 0, 'tax_cents' => 0];
                }
                $docRates[$rate]['subtotal_cents'] -= $item['subtotal_cents'];
                $docRates[$rate]['tax_cents'] -= $item['tax_cents'];
            }

            $documents[] = [
                'document_type' => 'credit_note',
                'id' => $cn['id'],
                'document_number' => $cn['document_number'],
                'date' => $cn['issue_date'],
                'contact_id' => $cn['customer_id'],
                'contact_name' => $contact['name'],
                'contact_tax_id' => $contact['tax_id'],
                'direction' => 'output',
                'subtotal_cents' => -$cn['subtotal_cents'],
                'tax_cents' => -$cn['tax_cents'],
                'total_cents' => -$cn['total_cents'],
                'rates' => array_values($docRates),
            ];
        }

        // 3. Process Supplier Bills (Input VAT +)
        foreach ($bills as $bill) {
            $contact = $resolveContact($bill['supplier_id']);
            /** @var array<int, array{rate_percent: int, subtotal_cents: int, tax_cents: int}> $docRates */
            $docRates = [];

            foreach ($bill['items'] as $item) {
                $rate = $item['tax_rate_percent'];
                if (! isset($rateBuckets[$rate])) {
                    $rateBuckets[$rate] = [
                        'tax_rate_percent' => $rate,
                        'sales_taxable_cents' => 0,
                        'output_vat_cents' => 0,
                        'purchases_taxable_cents' => 0,
                        'input_vat_cents' => 0,
                    ];
                }
                $rateBuckets[$rate]['purchases_taxable_cents'] += $item['subtotal_cents'];
                $rateBuckets[$rate]['input_vat_cents'] += $item['tax_cents'];

                if (! isset($docRates[$rate])) {
                    $docRates[$rate] = ['rate_percent' => $rate, 'subtotal_cents' => 0, 'tax_cents' => 0];
                }
                $docRates[$rate]['subtotal_cents'] += $item['subtotal_cents'];
                $docRates[$rate]['tax_cents'] += $item['tax_cents'];
            }

            $documents[] = [
                'document_type' => 'supplier_bill',
                'id' => $bill['id'],
                'document_number' => $bill['document_number'],
                'date' => $bill['bill_date'],
                'contact_id' => $bill['supplier_id'],
                'contact_name' => $contact['name'],
                'contact_tax_id' => $contact['tax_id'],
                'direction' => 'input',
                'subtotal_cents' => $bill['subtotal_cents'],
                'tax_cents' => $bill['tax_cents'],
                'total_cents' => $bill['total_cents'],
                'rates' => array_values($docRates),
            ];
        }

        // 4. Process Supplier Debit Notes (Input VAT -)
        foreach ($debitNotes as $dn) {
            $contact = $resolveContact($dn['supplier_id']);
            /** @var array<int, array{rate_percent: int, subtotal_cents: int, tax_cents: int}> $docRates */
            $docRates = [];

            foreach ($dn['items'] as $item) {
                $rate = $item['tax_rate_percent'];
                if (! isset($rateBuckets[$rate])) {
                    $rateBuckets[$rate] = [
                        'tax_rate_percent' => $rate,
                        'sales_taxable_cents' => 0,
                        'output_vat_cents' => 0,
                        'purchases_taxable_cents' => 0,
                        'input_vat_cents' => 0,
                    ];
                }
                $rateBuckets[$rate]['purchases_taxable_cents'] -= $item['subtotal_cents'];
                $rateBuckets[$rate]['input_vat_cents'] -= $item['tax_cents'];

                if (! isset($docRates[$rate])) {
                    $docRates[$rate] = ['rate_percent' => $rate, 'subtotal_cents' => 0, 'tax_cents' => 0];
                }
                $docRates[$rate]['subtotal_cents'] -= $item['subtotal_cents'];
                $docRates[$rate]['tax_cents'] -= $item['tax_cents'];
            }

            $documents[] = [
                'document_type' => 'debit_note',
                'id' => $dn['id'],
                'document_number' => $dn['document_number'],
                'date' => $dn['issue_date'],
                'contact_id' => $dn['supplier_id'],
                'contact_name' => $contact['name'],
                'contact_tax_id' => $contact['tax_id'],
                'direction' => 'input',
                'subtotal_cents' => -$dn['subtotal_cents'],
                'tax_cents' => -$dn['tax_cents'],
                'total_cents' => -$dn['total_cents'],
                'rates' => array_values($docRates),
            ];
        }

        // Sort rate breakdown descending by rate
        krsort($rateBuckets);
        $rateBreakdown = [];
        $totalSalesTaxable = 0;
        $totalOutputVat = 0;
        $totalPurchasesTaxable = 0;
        $totalInputVat = 0;

        foreach ($rateBuckets as $bucket) {
            $salesTotal = $bucket['sales_taxable_cents'] + $bucket['output_vat_cents'];
            $purchasesTotal = $bucket['purchases_taxable_cents'] + $bucket['input_vat_cents'];
            $netVat = $bucket['output_vat_cents'] - $bucket['input_vat_cents'];

            $totalSalesTaxable += $bucket['sales_taxable_cents'];
            $totalOutputVat += $bucket['output_vat_cents'];
            $totalPurchasesTaxable += $bucket['purchases_taxable_cents'];
            $totalInputVat += $bucket['input_vat_cents'];

            $rateBreakdown[] = [
                'tax_rate_percent' => $bucket['tax_rate_percent'],
                'sales_taxable_cents' => $bucket['sales_taxable_cents'],
                'output_vat_cents' => $bucket['output_vat_cents'],
                'sales_total_cents' => $salesTotal,
                'purchases_taxable_cents' => $bucket['purchases_taxable_cents'],
                'input_vat_cents' => $bucket['input_vat_cents'],
                'purchases_total_cents' => $purchasesTotal,
                'net_vat_cents' => $netVat,
            ];
        }

        $totalSalesGross = $totalSalesTaxable + $totalOutputVat;
        $totalPurchasesGross = $totalPurchasesTaxable + $totalInputVat;
        $netVatPayable = $totalOutputVat - $totalInputVat;

        // General Ledger Reconciliation
        $glOutputVatCents = 0;
        $glInputVatCents = 0;

        try {
            $outputAccount = $this->accountService->getSystemAccount(SystemAccount::OutputVat);
            $inputAccount = $this->accountService->getSystemAccount(SystemAccount::InputVat);

            $outputLines = $this->db->connection('tenant')
                ->table('journal_entry_lines')
                ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
                ->where('journal_entry_lines.account_id', $outputAccount->id)
                ->where('journal_entries.status', JournalEntryStatus::Posted->value)
                ->whereDate('journal_entries.entry_date', '>=', $fromDate->toDateString())
                ->whereDate('journal_entries.entry_date', '<=', $toDate->toDateString());

            $glOutputCredits = (int) $outputLines->sum('journal_entry_lines.credit_cents');
            $glOutputDebits = (int) $outputLines->sum('journal_entry_lines.debit_cents');
            $glOutputVatCents = $glOutputCredits - $glOutputDebits;

            $inputLines = $this->db->connection('tenant')
                ->table('journal_entry_lines')
                ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
                ->where('journal_entry_lines.account_id', $inputAccount->id)
                ->where('journal_entries.status', JournalEntryStatus::Posted->value)
                ->whereDate('journal_entries.entry_date', '>=', $fromDate->toDateString())
                ->whereDate('journal_entries.entry_date', '<=', $toDate->toDateString());

            $glInputDebits = (int) $inputLines->sum('journal_entry_lines.debit_cents');
            $glInputCredits = (int) $inputLines->sum('journal_entry_lines.credit_cents');
            $glInputVatCents = $glInputDebits - $glInputCredits;
        } catch (\Throwable) {
            // If system accounts are not yet seeded or in non-accounting environment
        }

        $glNetVatCents = $glOutputVatCents - $glInputVatCents;
        $outputDiscrepancy = $totalOutputVat - $glOutputVatCents;
        $inputDiscrepancy = $totalInputVat - $glInputVatCents;
        $netDiscrepancy = $netVatPayable - $glNetVatCents;
        $isReconciled = ($outputDiscrepancy === 0 && $inputDiscrepancy === 0);

        // Sort documents by date asc
        usort($documents, function (array $a, array $b): int {
            $cmp = strcmp($a['date'], $b['date']);

            return $cmp !== 0 ? $cmp : strcmp($a['document_number'], $b['document_number']);
        });

        return [
            'from_date' => $fromDate->toDateString(),
            'to_date' => $toDate->toDateString(),
            'fiscal_period' => $periodMeta,
            'summary' => [
                'total_sales_taxable_cents' => $totalSalesTaxable,
                'total_output_vat_cents' => $totalOutputVat,
                'total_sales_gross_cents' => $totalSalesGross,
                'total_purchases_taxable_cents' => $totalPurchasesTaxable,
                'total_input_vat_cents' => $totalInputVat,
                'total_purchases_gross_cents' => $totalPurchasesGross,
                'net_vat_payable_cents' => $netVatPayable,
            ],
            'rate_breakdown' => $rateBreakdown,
            'ledger_reconciliation' => [
                'gl_output_vat_cents' => $glOutputVatCents,
                'gl_input_vat_cents' => $glInputVatCents,
                'gl_net_vat_cents' => $glNetVatCents,
                'output_vat_discrepancy_cents' => $outputDiscrepancy,
                'input_vat_discrepancy_cents' => $inputDiscrepancy,
                'net_vat_discrepancy_cents' => $netDiscrepancy,
                'is_reconciled' => $isReconciled,
            ],
            'documents' => $documents,
        ];
    }

    public function file(FileVatReturnData $data, string $userId): VatReturn
    {
        return $this->db->connection('tenant')->transaction(function () use ($data, $userId): VatReturn {
            // Check for existing filed return covering this period
            if ($data->periodId !== null) {
                $alreadyFiled = VatReturn::where('fiscal_period_id', $data->periodId)
                    ->where('status', VatReturnStatus::Filed)
                    ->exists();
            } else {
                $alreadyFiled = VatReturn::where('from_date', $data->fromDate->toDateString())
                    ->where('to_date', $data->toDate->toDateString())
                    ->where('status', VatReturnStatus::Filed)
                    ->exists();
            }

            if ($alreadyFiled) {
                throw ApiException::badRequest(
                    'A VAT return has already been filed for this period.',
                    'VAT_RETURN_ALREADY_FILED'
                );
            }

            $calcData = new CalculateVatReturnData(
                fromDate: $data->fromDate,
                toDate: $data->toDate,
                periodId: $data->periodId,
            );
            $calculated = $this->calculate($calcData);

            $returnNumber = $this->documentNumbering->generateNext(DocumentType::VatReturn);

            return VatReturn::create([
                'return_number' => $returnNumber,
                'fiscal_period_id' => $data->periodId,
                'from_date' => $calculated['from_date'],
                'to_date' => $calculated['to_date'],
                'status' => VatReturnStatus::Filed,
                'sales_taxable_cents' => $calculated['summary']['total_sales_taxable_cents'],
                'output_vat_cents' => $calculated['summary']['total_output_vat_cents'],
                'purchases_taxable_cents' => $calculated['summary']['total_purchases_taxable_cents'],
                'input_vat_cents' => $calculated['summary']['total_input_vat_cents'],
                'net_vat_cents' => $calculated['summary']['net_vat_payable_cents'],
                'rate_breakdown' => $calculated['rate_breakdown'],
                'is_reconciled' => $calculated['ledger_reconciliation']['is_reconciled'],
                'gl_output_vat_cents' => $calculated['ledger_reconciliation']['gl_output_vat_cents'],
                'gl_input_vat_cents' => $calculated['ledger_reconciliation']['gl_input_vat_cents'],
                'gl_discrepancy_cents' => $calculated['ledger_reconciliation']['net_vat_discrepancy_cents'],
                'filed_by_user_id' => $userId,
                'filed_at' => now(),
                'notes' => $data->notes,
            ]);
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, VatReturn>
     */
    public function list(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $query = VatReturn::with('fiscalPeriod')->orderByDesc('from_date')->orderByDesc('created_at');

        if (! empty($filters['fiscal_period_id'])) {
            $query->where('fiscal_period_id', $filters['fiscal_period_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['search'])) {
            $search = (string) $filters['search'];
            $query->where('return_number', 'like', "%{$search}%");
        }

        $perPage = min(max((int) ($filters['per_page'] ?? $perPage), 1), 100);

        /** @var LengthAwarePaginator<int, VatReturn> $paginator */
        $paginator = $query->paginate($perPage);

        return $paginator;
    }

    public function find(string $id): VatReturn
    {
        /** @var VatReturn|null $vatReturn */
        $vatReturn = VatReturn::with('fiscalPeriod')->find($id);

        if ($vatReturn === null) {
            throw ApiException::notFound("VAT return '{$id}' not found.", 'VAT_RETURN_NOT_FOUND');
        }

        return $vatReturn;
    }

    /**
     * @param  array<string, mixed>  $calculationData
     */
    public function renderPdf(array $calculationData, ?string $returnNumber = null): string
    {
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
            'currency' => 'USD',
        ];

        $html = view('accounting::pdf.vat_return', [
            'data' => $calculationData,
            'company' => $company,
            'returnNumber' => $returnNumber,
            'generatedAt' => now()->toFormattedDateString(),
        ])->render();

        return $this->pdfRenderer->renderHtml($html);
    }
}
