<?php

declare(strict_types=1);

namespace Modules\Contacts\Services;

use Carbon\Carbon;
use Modules\Purchasing\Services\SupplierBillService;
use Modules\Sales\Services\SalesInvoiceService;

final class AgingReportService
{
    public function __construct(
        private readonly SalesInvoiceService $salesInvoiceService,
        private readonly SupplierBillService $supplierBillService,
        private readonly ContactService $contactService,
    ) {}

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
     *     customers: list<array{
     *         customer_id: string,
     *         customer_code: string,
     *         customer_name: string,
     *         current_cents: int,
     *         days_1_30_cents: int,
     *         days_31_60_cents: int,
     *         days_61_90_cents: int,
     *         days_over_90_cents: int,
     *         total_due_cents: int,
     *         invoice_count: int,
     *     }>,
     *     invoices?: list<array<string, mixed>>,
     * }
     */
    public function getArAging(?string $asOfDate = null, ?string $customerId = null): array
    {
        $asOf = $asOfDate !== null ? Carbon::parse($asOfDate) : Carbon::today();
        $agingData = $this->salesInvoiceService->getArAgingSummary($asOf, $customerId);

        $customerIds = array_column($agingData['customers'], 'customer_id');
        $contactSummaries = $this->contactService->getSummariesByIds($customerIds);

        $enrichedCustomers = [];
        foreach ($agingData['customers'] as $row) {
            $cId = $row['customer_id'];
            $contact = $contactSummaries[$cId] ?? null;

            $enrichedCustomers[] = [
                'customer_id' => $cId,
                'customer_code' => $contact !== null ? $contact['code'] : 'N/A',
                'customer_name' => $contact !== null ? $contact['name'] : 'Unknown Customer',
                'current_cents' => $row['current_cents'],
                'days_1_30_cents' => $row['days_1_30_cents'],
                'days_31_60_cents' => $row['days_31_60_cents'],
                'days_61_90_cents' => $row['days_61_90_cents'],
                'days_over_90_cents' => $row['days_over_90_cents'],
                'total_due_cents' => $row['total_due_cents'],
                'invoice_count' => $row['invoice_count'],
            ];
        }

        $result = [
            'as_of_date' => $agingData['as_of_date'],
            'summary' => $agingData['summary'],
            'customers' => $enrichedCustomers,
        ];

        if ($customerId !== null && trim($customerId) !== '') {
            $singleDetails = $this->salesInvoiceService->getAgingBuckets($customerId, $asOf);
            $result['invoices'] = $singleDetails['invoices'];
        }

        return $result;
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
     *         supplier_code: string,
     *         supplier_name: string,
     *         current_cents: int,
     *         days_1_30_cents: int,
     *         days_31_60_cents: int,
     *         days_61_90_cents: int,
     *         days_over_90_cents: int,
     *         total_due_cents: int,
     *         bill_count: int,
     *     }>,
     *     bills?: list<array<string, mixed>>,
     * }
     */
    public function getApAging(?string $asOfDate = null, ?string $supplierId = null): array
    {
        $asOf = $asOfDate !== null ? Carbon::parse($asOfDate) : Carbon::today();
        $agingData = $this->supplierBillService->getApAgingSummary($asOf, $supplierId);

        $supplierIds = array_column($agingData['suppliers'], 'supplier_id');
        $contactSummaries = $this->contactService->getSummariesByIds($supplierIds);

        $enrichedSuppliers = [];
        foreach ($agingData['suppliers'] as $row) {
            $sId = $row['supplier_id'];
            $contact = $contactSummaries[$sId] ?? null;

            $enrichedSuppliers[] = [
                'supplier_id' => $sId,
                'supplier_code' => $contact !== null ? $contact['code'] : 'N/A',
                'supplier_name' => $contact !== null ? $contact['name'] : 'Unknown Supplier',
                'current_cents' => $row['current_cents'],
                'days_1_30_cents' => $row['days_1_30_cents'],
                'days_31_60_cents' => $row['days_31_60_cents'],
                'days_61_90_cents' => $row['days_61_90_cents'],
                'days_over_90_cents' => $row['days_over_90_cents'],
                'total_due_cents' => $row['total_due_cents'],
                'bill_count' => $row['bill_count'],
            ];
        }

        $result = [
            'as_of_date' => $agingData['as_of_date'],
            'summary' => $agingData['summary'],
            'suppliers' => $enrichedSuppliers,
        ];

        if ($supplierId !== null && trim($supplierId) !== '') {
            $singleDetails = $this->supplierBillService->getAgingBuckets($supplierId, $asOf);
            $result['bills'] = $singleDetails['bills'];
        }

        return $result;
    }
}
