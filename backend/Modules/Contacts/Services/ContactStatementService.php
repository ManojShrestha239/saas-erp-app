<?php

declare(strict_types=1);

namespace Modules\Contacts\Services;

use Carbon\Carbon;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Response;
use Modules\Contacts\Enums\ContactType;
use Modules\Payments\Enums\PaymentType;
use Modules\Payments\Services\PaymentService;
use Modules\Purchasing\Services\SupplierBillService;
use Modules\Sales\Services\SalesInvoiceService;
use Shared\Exceptions\ApiException;
use Shared\Services\PdfRendererService;
use Shared\Tenancy\TenantContext;

final class ContactStatementService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly ContactService $contactService,
        private readonly SalesInvoiceService $invoiceService,
        private readonly SupplierBillService $billService,
        private readonly PaymentService $paymentService,
        private readonly PdfRendererService $pdfRenderer,
    ) {}

    /**
     * @return array{
     *     contact: array{id: string, code: string, name: string, type: string, email: ?string, phone: ?string, tax_id: ?string, billing_address: ?array<string, mixed>, shipping_address: ?array<string, mixed>, payment_terms_days: int},
     *     aging_type: 'customer'|'supplier',
     *     as_of_date: string,
     *     current_cents: int,
     *     days_1_30_cents: int,
     *     days_31_60_cents: int,
     *     days_61_90_cents: int,
     *     days_over_90_cents: int,
     *     total_due_cents: int,
     *     unallocated_amount_cents: int,
     *     net_balance_cents: int,
     *     documents: list<array<string, mixed>>
     * }
     */
    public function getAging(string $contactId, ?Carbon $asOfDate = null, ?string $type = null): array
    {
        $contact = $this->contactService->findById($contactId);
        if ($contact === null) {
            throw ApiException::notFound("Contact '{$contactId}' not found.", 'CONTACT_NOT_FOUND');
        }

        $contactDetails = $this->contactService->getDetails($contactId);
        if ($contactDetails === null) {
            throw ApiException::notFound("Contact '{$contactId}' not found.", 'CONTACT_NOT_FOUND');
        }

        $resolvedType = $this->resolveType($contact->type, $type);
        $asOf = $asOfDate ?? Carbon::today();

        if ($resolvedType === 'customer') {
            $aging = $this->invoiceService->getAgingBuckets($contactId, $asOf);
            $unallocated = $this->paymentService->getUnallocatedCredit($contactId, PaymentType::Inbound);

            return [
                'contact' => $contactDetails,
                'aging_type' => 'customer',
                'as_of_date' => $aging['as_of_date'],
                'current_cents' => $aging['current_cents'],
                'days_1_30_cents' => $aging['days_1_30_cents'],
                'days_31_60_cents' => $aging['days_31_60_cents'],
                'days_61_90_cents' => $aging['days_61_90_cents'],
                'days_over_90_cents' => $aging['days_over_90_cents'],
                'total_due_cents' => $aging['total_due_cents'],
                'unallocated_amount_cents' => $unallocated,
                'net_balance_cents' => $aging['total_due_cents'] - $unallocated,
                'documents' => $aging['invoices'],
            ];
        }

        $aging = $this->billService->getAgingBuckets($contactId, $asOf);
        $unallocated = $this->paymentService->getUnallocatedCredit($contactId, PaymentType::Outbound);

        return [
            'contact' => $contactDetails,
            'aging_type' => 'supplier',
            'as_of_date' => $aging['as_of_date'],
            'current_cents' => $aging['current_cents'],
            'days_1_30_cents' => $aging['days_1_30_cents'],
            'days_31_60_cents' => $aging['days_31_60_cents'],
            'days_61_90_cents' => $aging['days_61_90_cents'],
            'days_over_90_cents' => $aging['days_over_90_cents'],
            'total_due_cents' => $aging['total_due_cents'],
            'unallocated_amount_cents' => $unallocated,
            'net_balance_cents' => $aging['total_due_cents'] - $unallocated,
            'documents' => $aging['bills'],
        ];
    }

    /**
     * @return array{
     *     contact: array{id: string, code: string, name: string, type: string, email: ?string, phone: ?string, tax_id: ?string, billing_address: ?array<string, mixed>, shipping_address: ?array<string, mixed>, payment_terms_days: int},
     *     company: array{name: string, tax_id: ?string, address: ?string, email: ?string, phone: ?string, bank_details: ?string, currency: string},
     *     statement_type: 'customer'|'supplier',
     *     start_date: string,
     *     end_date: string,
     *     currency: string,
     *     summary: array{
     *         opening_balance_cents: int,
     *         total_debits_cents: int,
     *         total_credits_cents: int,
     *         closing_balance_cents: int,
     *         unallocated_amount_cents: int,
     *         net_balance_due_cents: int
     *     },
     *     aging: array{
     *         current_cents: int,
     *         days_1_30_cents: int,
     *         days_31_60_cents: int,
     *         days_61_90_cents: int,
     *         days_over_90_cents: int,
     *         total_due_cents: int
     *     },
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
     *         status: string,
     *         running_balance_cents: int
     *     }>
     * }
     */
    public function getStatement(
        string $contactId,
        ?Carbon $startDate = null,
        ?Carbon $endDate = null,
        ?string $statementType = null
    ): array {
        $contact = $this->contactService->findById($contactId);
        if ($contact === null) {
            throw ApiException::notFound("Contact '{$contactId}' not found.", 'CONTACT_NOT_FOUND');
        }

        $contactDetails = $this->contactService->getDetails($contactId);
        if ($contactDetails === null) {
            throw ApiException::notFound("Contact '{$contactId}' not found.", 'CONTACT_NOT_FOUND');
        }

        $resolvedType = $this->resolveType($contact->type, $statementType);

        $end = ($endDate ?? Carbon::today())->startOfDay();
        $start = ($startDate ?? $end->copy()->startOfMonth())->startOfDay();

        if ($start->gt($end)) {
            throw ApiException::unprocessable('Start date cannot be after end date.', 'INVALID_DATE_RANGE');
        }

        $tenant = TenantContext::get();
        /** @var array<string, string> $settings */
        $settings = $this->db->connection('tenant')
            ->table('settings')
            ->pluck('value', 'key')
            ->all();

        $currency = $settings['currency'] ?? 'USD';

        $company = [
            'name' => $settings['company_name'] ?? ($tenant !== null ? ucfirst($tenant->slug) : 'NexusERP Company'),
            'tax_id' => $settings['company_tax_id'] ?? $settings['tax_number'] ?? null,
            'address' => $settings['company_address'] ?? null,
            'email' => $settings['company_email'] ?? null,
            'phone' => $settings['company_phone'] ?? null,
            'bank_details' => $settings['bank_details'] ?? null,
            'currency' => $currency,
        ];

        if ($resolvedType === 'customer') {
            $invoiceData = $this->invoiceService->getStatementTransactions($contactId, $start, $end);
            $paymentData = $this->paymentService->getStatementTransactions($contactId, PaymentType::Inbound, $start, $end);

            $periodOpeningCents = $contact->opening_balance_cents
                + $invoiceData['prior_invoiced_cents']
                - $paymentData['prior_paid_cents'];

            $allTransactions = array_merge($invoiceData['transactions'], $paymentData['transactions']);
            usort($allTransactions, function (array $a, array $b): int {
                $dateComp = strcmp($a['date'], $b['date']);
                if ($dateComp !== 0) {
                    return $dateComp;
                }
                if ($a['type'] !== $b['type']) {
                    return $a['type'] === 'invoice' ? -1 : 1;
                }

                return strcmp($a['document_number'], $b['document_number']);
            });

            $runningBalanceCents = $periodOpeningCents;
            $totalDebitsCents = 0;
            $totalCreditsCents = 0;
            $ledger = [];

            foreach ($allTransactions as $tx) {
                $runningBalanceCents += ($tx['debit_cents'] - $tx['credit_cents']);
                $totalDebitsCents += $tx['debit_cents'];
                $totalCreditsCents += $tx['credit_cents'];
                $tx['running_balance_cents'] = $runningBalanceCents;
                $ledger[] = $tx;
            }

            $closingBalanceCents = $periodOpeningCents + $totalDebitsCents - $totalCreditsCents;
            $aging = $this->invoiceService->getAgingBuckets($contactId, $end);
            $unallocated = $this->paymentService->getUnallocatedCredit($contactId, PaymentType::Inbound);

            return [
                'contact' => $contactDetails,
                'company' => $company,
                'statement_type' => 'customer',
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'currency' => $currency,
                'summary' => [
                    'opening_balance_cents' => $periodOpeningCents,
                    'total_debits_cents' => $totalDebitsCents,
                    'total_credits_cents' => $totalCreditsCents,
                    'closing_balance_cents' => $closingBalanceCents,
                    'unallocated_amount_cents' => $unallocated,
                    'net_balance_due_cents' => $closingBalanceCents,
                ],
                'aging' => [
                    'current_cents' => $aging['current_cents'],
                    'days_1_30_cents' => $aging['days_1_30_cents'],
                    'days_31_60_cents' => $aging['days_31_60_cents'],
                    'days_61_90_cents' => $aging['days_61_90_cents'],
                    'days_over_90_cents' => $aging['days_over_90_cents'],
                    'total_due_cents' => $aging['total_due_cents'],
                ],
                'transactions' => $ledger,
            ];
        }

        // Supplier statement
        $billData = $this->billService->getStatementTransactions($contactId, $start, $end);
        $paymentData = $this->paymentService->getStatementTransactions($contactId, PaymentType::Outbound, $start, $end);

        $periodOpeningCents = $contact->opening_balance_cents
            + $billData['prior_billed_cents']
            - $paymentData['prior_paid_cents'];

        $allTransactions = array_merge($billData['transactions'], $paymentData['transactions']);
        usort($allTransactions, function (array $a, array $b): int {
            $dateComp = strcmp($a['date'], $b['date']);
            if ($dateComp !== 0) {
                return $dateComp;
            }
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'bill' ? -1 : 1;
            }

            return strcmp($a['document_number'], $b['document_number']);
        });

        $runningBalanceCents = $periodOpeningCents;
        $totalDebitsCents = 0;  // Payments made to supplier
        $totalCreditsCents = 0; // Bills received from supplier
        $ledger = [];

        foreach ($allTransactions as $tx) {
            $runningBalanceCents += ($tx['credit_cents'] - $tx['debit_cents']);
            $totalDebitsCents += $tx['debit_cents'];
            $totalCreditsCents += $tx['credit_cents'];
            $tx['running_balance_cents'] = $runningBalanceCents;
            $ledger[] = $tx;
        }

        $closingBalanceCents = $periodOpeningCents + $totalCreditsCents - $totalDebitsCents;
        $aging = $this->billService->getAgingBuckets($contactId, $end);
        $unallocated = $this->paymentService->getUnallocatedCredit($contactId, PaymentType::Outbound);

        return [
            'contact' => $contactDetails,
            'company' => $company,
            'statement_type' => 'supplier',
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'currency' => $currency,
            'summary' => [
                'opening_balance_cents' => $periodOpeningCents,
                'total_debits_cents' => $totalDebitsCents,
                'total_credits_cents' => $totalCreditsCents,
                'closing_balance_cents' => $closingBalanceCents,
                'unallocated_amount_cents' => $unallocated,
                'net_balance_due_cents' => $closingBalanceCents,
            ],
            'aging' => [
                'current_cents' => $aging['current_cents'],
                'days_1_30_cents' => $aging['days_1_30_cents'],
                'days_31_60_cents' => $aging['days_31_60_cents'],
                'days_61_90_cents' => $aging['days_61_90_cents'],
                'days_over_90_cents' => $aging['days_over_90_cents'],
                'total_due_cents' => $aging['total_due_cents'],
            ],
            'transactions' => $ledger,
        ];
    }

    public function renderPdf(
        string $contactId,
        ?Carbon $startDate = null,
        ?Carbon $endDate = null,
        ?string $statementType = null,
        bool $download = false
    ): Response {
        $statement = $this->getStatement($contactId, $startDate, $endDate, $statementType);

        $filename = sprintf(
            'statement-%s-%s-to-%s.pdf',
            $statement['contact']['code'],
            $statement['start_date'],
            $statement['end_date']
        );

        if ($download) {
            return $this->pdfRenderer->downloadView('contacts::pdf.statement', [
                'statement' => $statement,
            ], $filename);
        }

        return $this->pdfRenderer->streamView('contacts::pdf.statement', [
            'statement' => $statement,
        ], $filename);
    }

    /**
     * @return 'customer'|'supplier'
     */
    private function resolveType(ContactType $contactType, ?string $requestedType): string
    {
        if ($requestedType !== null && trim($requestedType) !== '') {
            $norm = strtolower(trim($requestedType));
            if ($norm === 'customer') {
                if ($contactType === ContactType::Supplier) {
                    throw ApiException::unprocessable('Contact is a supplier and does not have customer records.', 'INVALID_STATEMENT_TYPE');
                }

                return 'customer';
            }

            if ($norm === 'supplier') {
                if ($contactType === ContactType::Customer) {
                    throw ApiException::unprocessable('Contact is a customer and does not have supplier records.', 'INVALID_STATEMENT_TYPE');
                }

                return 'supplier';
            }

            throw ApiException::unprocessable("Invalid statement type '{$requestedType}'. Must be 'customer' or 'supplier'.", 'INVALID_STATEMENT_TYPE');
        }

        return $contactType === ContactType::Supplier ? 'supplier' : 'customer';
    }
}
