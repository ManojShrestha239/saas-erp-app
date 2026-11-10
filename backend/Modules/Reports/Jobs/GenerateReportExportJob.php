<?php

declare(strict_types=1);

namespace Modules\Reports\Jobs;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Accounting\Services\FinancialReportService;
use Modules\Contacts\Services\AgingReportService;
use Modules\Inventory\Http\Resources\StockMovementResource;
use Modules\Inventory\Services\InventoryCostingService;
use Modules\Inventory\Services\StockService;
use Modules\Reports\Enums\ReportExportStatus;
use Modules\Reports\Enums\ReportFormat;
use Modules\Reports\Enums\ReportType;
use Modules\Reports\Exports\AgingExport;
use Modules\Reports\Exports\BalanceSheetExport;
use Modules\Reports\Exports\ProfitAndLossExport;
use Modules\Reports\Exports\StockMovementExport;
use Modules\Reports\Exports\StockValuationExport;
use Modules\Reports\Exports\TrialBalanceExport;
use Modules\Reports\Models\ReportExport;
use Shared\Services\PdfRendererService;
use Shared\Tenancy\TenantContext;
use Shared\Tenancy\TenantManager;
use Throwable;

final class GenerateReportExportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 300;

    public int $tries = 2;

    public function __construct(
        public readonly string $exportId,
        public readonly string $tenantId,
        public readonly string $databaseName,
    ) {
        $this->onQueue('reports');
    }

    public function handle(
        TenantManager $tenantManager,
        PdfRendererService $pdfRenderer,
        FinancialReportService $financialReportService,
        AgingReportService $agingReportService,
        InventoryCostingService $inventoryCostingService,
        StockService $stockService,
    ): void {
        $context = new TenantContext(
            id: $this->tenantId,
            slug: '',
            databaseName: $this->databaseName,
        );

        $tenantManager->switchTo($context);

        $export = ReportExport::query()->findOrFail($this->exportId);

        $export->update([
            'status' => ReportExportStatus::Processing,
        ]);

        try {
            $company = $this->resolveCompanyMetadata();
            $filters = $export->filters ?? [];
            $relativePath = "tenants/{$this->tenantId}/exports/{$export->id}.{$export->format->value}";

            Storage::disk('local')->makeDirectory("tenants/{$this->tenantId}/exports");

            if ($export->format === ReportFormat::Pdf) {
                [$view, $viewData] = $this->resolvePdfPayload(
                    export: $export,
                    company: $company,
                    filters: $filters,
                    financialReportService: $financialReportService,
                    agingReportService: $agingReportService,
                    inventoryCostingService: $inventoryCostingService,
                    stockService: $stockService,
                );

                $pdfBytes = $pdfRenderer->renderView($view, $viewData);
                Storage::disk('local')->put($relativePath, $pdfBytes);
                $fileSize = strlen($pdfBytes);
            } else {
                $exportable = $this->resolveExcelPayload(
                    export: $export,
                    filters: $filters,
                    financialReportService: $financialReportService,
                    agingReportService: $agingReportService,
                    inventoryCostingService: $inventoryCostingService,
                    stockService: $stockService,
                );

                Excel::store($exportable, $relativePath, 'local');
                $fileSize = (int) Storage::disk('local')->size($relativePath);
            }

            $export->update([
                'status' => ReportExportStatus::Completed,
                'file_path' => $relativePath,
                'file_size_bytes' => $fileSize,
            ]);
        } catch (Throwable $e) {
            Log::error('Report export generation failed: '.$e->getMessage(), [
                'export_id' => $export->id,
                'tenant_id' => $this->tenantId,
                'trace' => $e->getTraceAsString(),
            ]);

            $export->update([
                'status' => ReportExportStatus::Failed,
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        try {
            $context = new TenantContext(
                id: $this->tenantId,
                slug: '',
                databaseName: $this->databaseName,
            );
            app(TenantManager::class)->switchTo($context);

            $export = ReportExport::query()->find($this->exportId);
            $export?->update([
                'status' => ReportExportStatus::Failed,
                'error_message' => $exception->getMessage(),
            ]);
        } catch (Throwable) {
            // Best effort status update
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $company
     * @return array{0: view-string, 1: array<string, mixed>}
     */
    private function resolvePdfPayload(
        ReportExport $export,
        array $company,
        array $filters,
        FinancialReportService $financialReportService,
        AgingReportService $agingReportService,
        InventoryCostingService $inventoryCostingService,
        StockService $stockService,
    ): array {
        switch ($export->report_type) {
            case ReportType::TrialBalance:
                $asOfDate = isset($filters['as_of_date']) ? (string) $filters['as_of_date'] : null;
                $report = $financialReportService->getTrialBalance($asOfDate);

                return [
                    'reports::pdf.trial_balance',
                    [
                        'company' => $company,
                        'asOfDate' => $report->asOfDate,
                        'report' => $report,
                    ],
                ];

            case ReportType::ProfitAndLoss:
                $startDate = isset($filters['start_date']) && (string) $filters['start_date'] !== ''
                    ? (string) $filters['start_date']
                    : Carbon::now()->startOfMonth()->toDateString();
                $endDate = isset($filters['end_date']) && (string) $filters['end_date'] !== ''
                    ? (string) $filters['end_date']
                    : Carbon::now()->toDateString();
                $report = $financialReportService->getProfitAndLoss($startDate, $endDate);

                return [
                    'reports::pdf.profit_and_loss',
                    [
                        'company' => $company,
                        'startDate' => $report->startDate,
                        'endDate' => $report->endDate,
                        'report' => $report,
                    ],
                ];

            case ReportType::BalanceSheet:
                $asOfDate = isset($filters['as_of_date']) ? (string) $filters['as_of_date'] : null;
                $report = $financialReportService->getBalanceSheet($asOfDate);

                return [
                    'reports::pdf.balance_sheet',
                    [
                        'company' => $company,
                        'asOfDate' => $report->asOfDate,
                        'report' => $report,
                    ],
                ];

            case ReportType::ArAging:
                $asOfDate = isset($filters['as_of_date']) ? (string) $filters['as_of_date'] : null;
                $customerId = isset($filters['customer_id']) ? (string) $filters['customer_id'] : null;
                $aging = $agingReportService->getArAging($asOfDate, $customerId);

                $glArBalance = $financialReportService->getAccountBalanceByCode('1130', $asOfDate);
                $diff = $aging['summary']['total_due_cents'] - $glArBalance;
                $aging['gl_control_account_cents'] = $glArBalance;
                $aging['reconciliation_difference_cents'] = $diff;
                $aging['is_reconciled'] = ($diff === 0);

                return [
                    'reports::pdf.aging',
                    [
                        'company' => $company,
                        'asOfDate' => $aging['as_of_date'],
                        'type' => 'ar',
                        'data' => $aging,
                    ],
                ];

            case ReportType::ApAging:
                $asOfDate = isset($filters['as_of_date']) ? (string) $filters['as_of_date'] : null;
                $supplierId = isset($filters['supplier_id']) ? (string) $filters['supplier_id'] : null;
                $aging = $agingReportService->getApAging($asOfDate, $supplierId);

                $glApBalance = $financialReportService->getAccountBalanceByCode('2110', $asOfDate);
                $diff = $aging['summary']['total_due_cents'] - $glApBalance;
                $aging['gl_control_account_cents'] = $glApBalance;
                $aging['reconciliation_difference_cents'] = $diff;
                $aging['is_reconciled'] = ($diff === 0);

                return [
                    'reports::pdf.aging',
                    [
                        'company' => $company,
                        'asOfDate' => $aging['as_of_date'],
                        'type' => 'ap',
                        'data' => $aging,
                    ],
                ];

            case ReportType::StockValuation:
                $asOfDate = isset($filters['as_of_date']) ? (string) $filters['as_of_date'] : null;
                $warehouseId = isset($filters['warehouse_id']) && $filters['warehouse_id'] !== '' ? (string) $filters['warehouse_id'] : null;
                $categoryId = isset($filters['category_id']) && $filters['category_id'] !== '' ? (string) $filters['category_id'] : null;
                $search = isset($filters['search']) ? (string) $filters['search'] : null;

                $summary = $inventoryCostingService->getValuationSummary($warehouseId);
                $glInventoryBalance = $financialReportService->getAccountBalanceByCode('1150', $asOfDate);
                $diff = $summary->totalValuationCents - $glInventoryBalance;

                $valuationFilters = [
                    'warehouse_id' => $warehouseId,
                    'category_id' => $categoryId,
                    'search' => $search,
                ];
                $sort = ['field' => 'name', 'direction' => 'asc'];
                $paginated = $inventoryCostingService->paginateValuation($valuationFilters, $sort, 1000);

                $data = [
                    'as_of_date' => $asOfDate ?? Carbon::now()->toDateString(),
                    'summary' => [
                        'total_valuation_cents' => $summary->totalValuationCents,
                        'total_units_on_hand' => $summary->totalUnitsOnHand,
                        'total_products_count' => $summary->totalProductsCount,
                        'valued_products_count' => $summary->valuedProductsCount,
                        'currency' => $summary->currency,
                        'gl_control_account_cents' => $glInventoryBalance,
                        'reconciliation_difference_cents' => $diff,
                        'is_reconciled' => $diff === 0,
                    ],
                    'items' => array_map(fn ($item) => $item->toArray(), $paginated->items()),
                ];

                return [
                    'reports::pdf.stock_valuation',
                    [
                        'company' => $company,
                        'asOfDate' => $data['as_of_date'],
                        'data' => $data,
                    ],
                ];

            case ReportType::StockMovements:
                $movementFilters = [
                    'warehouse_id' => isset($filters['warehouse_id']) && $filters['warehouse_id'] !== '' ? (string) $filters['warehouse_id'] : null,
                    'product_id' => isset($filters['product_id']) && $filters['product_id'] !== '' ? (string) $filters['product_id'] : null,
                    'type' => isset($filters['type']) && $filters['type'] !== '' ? (string) $filters['type'] : null,
                    'date_from' => isset($filters['date_from']) ? (string) $filters['date_from'] : null,
                    'date_to' => isset($filters['date_to']) ? (string) $filters['date_to'] : null,
                ];

                $sort = ['field' => 'created_at', 'direction' => 'desc'];
                $paginated = $stockService->paginateMovements($movementFilters, $sort, 1000);
                /** @var list<array<string, mixed>> $items */
                $items = StockMovementResource::collection($paginated->items())->resolve();

                return [
                    'reports::pdf.stock_movements',
                    [
                        'company' => $company,
                        'items' => $items,
                    ],
                ];
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function resolveExcelPayload(
        ReportExport $export,
        array $filters,
        FinancialReportService $financialReportService,
        AgingReportService $agingReportService,
        InventoryCostingService $inventoryCostingService,
        StockService $stockService,
    ): Export {
        switch ($export->report_type) {
            case ReportType::TrialBalance:
                $asOfDate = isset($filters['as_of_date']) ? (string) $filters['as_of_date'] : null;
                $report = $financialReportService->getTrialBalance($asOfDate);

                return new TrialBalanceExport($report);

            case ReportType::ProfitAndLoss:
                $startDate = isset($filters['start_date']) && (string) $filters['start_date'] !== ''
                    ? (string) $filters['start_date']
                    : Carbon::now()->startOfMonth()->toDateString();
                $endDate = isset($filters['end_date']) && (string) $filters['end_date'] !== ''
                    ? (string) $filters['end_date']
                    : Carbon::now()->toDateString();
                $report = $financialReportService->getProfitAndLoss($startDate, $endDate);

                return new ProfitAndLossExport($report);

            case ReportType::BalanceSheet:
                $asOfDate = isset($filters['as_of_date']) ? (string) $filters['as_of_date'] : null;
                $report = $financialReportService->getBalanceSheet($asOfDate);

                return new BalanceSheetExport($report);

            case ReportType::ArAging:
                $asOfDate = isset($filters['as_of_date']) ? (string) $filters['as_of_date'] : null;
                $customerId = isset($filters['customer_id']) ? (string) $filters['customer_id'] : null;
                $aging = $agingReportService->getArAging($asOfDate, $customerId);

                $glArBalance = $financialReportService->getAccountBalanceByCode('1130', $asOfDate);
                $diff = $aging['summary']['total_due_cents'] - $glArBalance;
                $aging['gl_control_account_cents'] = $glArBalance;
                $aging['reconciliation_difference_cents'] = $diff;
                $aging['is_reconciled'] = ($diff === 0);

                return new AgingExport($aging, 'ar');

            case ReportType::ApAging:
                $asOfDate = isset($filters['as_of_date']) ? (string) $filters['as_of_date'] : null;
                $supplierId = isset($filters['supplier_id']) ? (string) $filters['supplier_id'] : null;
                $aging = $agingReportService->getApAging($asOfDate, $supplierId);

                $glApBalance = $financialReportService->getAccountBalanceByCode('2110', $asOfDate);
                $diff = $aging['summary']['total_due_cents'] - $glApBalance;
                $aging['gl_control_account_cents'] = $glApBalance;
                $aging['reconciliation_difference_cents'] = $diff;
                $aging['is_reconciled'] = ($diff === 0);

                return new AgingExport($aging, 'ap');

            case ReportType::StockValuation:
                $asOfDate = isset($filters['as_of_date']) ? (string) $filters['as_of_date'] : null;
                $warehouseId = isset($filters['warehouse_id']) && $filters['warehouse_id'] !== '' ? (string) $filters['warehouse_id'] : null;
                $categoryId = isset($filters['category_id']) && $filters['category_id'] !== '' ? (string) $filters['category_id'] : null;
                $search = isset($filters['search']) ? (string) $filters['search'] : null;

                $summary = $inventoryCostingService->getValuationSummary($warehouseId);
                $glInventoryBalance = $financialReportService->getAccountBalanceByCode('1150', $asOfDate);
                $diff = $summary->totalValuationCents - $glInventoryBalance;

                $valuationFilters = [
                    'warehouse_id' => $warehouseId,
                    'category_id' => $categoryId,
                    'search' => $search,
                ];
                $sort = ['field' => 'name', 'direction' => 'asc'];
                $paginated = $inventoryCostingService->paginateValuation($valuationFilters, $sort, 1000);

                $data = [
                    'as_of_date' => $asOfDate ?? Carbon::now()->toDateString(),
                    'summary' => [
                        'total_valuation_cents' => $summary->totalValuationCents,
                        'total_units_on_hand' => $summary->totalUnitsOnHand,
                        'total_products_count' => $summary->totalProductsCount,
                        'valued_products_count' => $summary->valuedProductsCount,
                        'currency' => $summary->currency,
                        'gl_control_account_cents' => $glInventoryBalance,
                        'reconciliation_difference_cents' => $diff,
                        'is_reconciled' => $diff === 0,
                    ],
                    'items' => array_map(fn ($item) => $item->toArray(), $paginated->items()),
                ];

                return new StockValuationExport($data);

            case ReportType::StockMovements:
                $movementFilters = [
                    'warehouse_id' => isset($filters['warehouse_id']) && $filters['warehouse_id'] !== '' ? (string) $filters['warehouse_id'] : null,
                    'product_id' => isset($filters['product_id']) && $filters['product_id'] !== '' ? (string) $filters['product_id'] : null,
                    'type' => isset($filters['type']) && $filters['type'] !== '' ? (string) $filters['type'] : null,
                    'date_from' => isset($filters['date_from']) ? (string) $filters['date_from'] : null,
                    'date_to' => isset($filters['date_to']) ? (string) $filters['date_to'] : null,
                ];

                $sort = ['field' => 'created_at', 'direction' => 'desc'];
                $paginated = $stockService->paginateMovements($movementFilters, $sort, 1000);
                /** @var list<array<string, mixed>> $items */
                $items = StockMovementResource::collection($paginated->items())->resolve();

                return new StockMovementExport($items);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveCompanyMetadata(): array
    {
        $settings = [];
        if (Schema::connection('tenant')->hasTable('settings')) {
            $settings = DB::connection('tenant')->table('settings')->pluck('value', 'key')->all();
        }

        $tenantContext = TenantContext::get();

        return [
            'name' => $settings['company_name'] ?? ($tenantContext !== null ? ucfirst($tenantContext->slug) : 'NexusERP'),
            'tax_id' => $settings['company_tax_id'] ?? $settings['tax_number'] ?? null,
            'address' => $settings['company_address'] ?? null,
            'email' => $settings['company_email'] ?? null,
            'phone' => $settings['company_phone'] ?? null,
            'currency' => $settings['currency'] ?? 'USD',
        ];
    }
}
