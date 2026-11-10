<?php

declare(strict_types=1);

namespace Modules\Reports\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Accounting\Services\FinancialReportService;
use Modules\Contacts\Services\AgingReportService;
use Modules\Inventory\Http\Resources\StockMovementResource;
use Modules\Inventory\Services\InventoryCostingService;
use Modules\Inventory\Services\StockService;
use Shared\Responses\ApiResponse;

final class OperationalReportController extends Controller
{
    public function __construct(
        private readonly AgingReportService $agingReportService,
        private readonly InventoryCostingService $inventoryCostingService,
        private readonly StockService $stockService,
        private readonly FinancialReportService $financialReportService,
    ) {}

    public function arAging(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'as_of_date' => ['nullable', 'date'],
            'customer_id' => ['nullable', 'string'],
        ]);

        $asOfDate = isset($validated['as_of_date']) ? (string) $validated['as_of_date'] : null;
        $customerId = isset($validated['customer_id']) ? (string) $validated['customer_id'] : null;

        $aging = $this->agingReportService->getArAging($asOfDate, $customerId);
        $glArBalance = $this->financialReportService->getAccountBalanceByCode('1130', $asOfDate);
        $totalDue = $aging['summary']['total_due_cents'];
        $diff = $totalDue - $glArBalance;

        return ApiResponse::success(
            data: [
                'as_of_date' => $aging['as_of_date'],
                'summary' => $aging['summary'],
                'customers' => $aging['customers'],
                'invoices' => $aging['invoices'] ?? null,
                'gl_control_account_cents' => $glArBalance,
                'reconciliation_difference_cents' => $diff,
                'is_reconciled' => $diff === 0,
            ],
            message: 'AR aging report generated successfully.'
        );
    }

    public function apAging(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'as_of_date' => ['nullable', 'date'],
            'supplier_id' => ['nullable', 'string'],
        ]);

        $asOfDate = isset($validated['as_of_date']) ? (string) $validated['as_of_date'] : null;
        $supplierId = isset($validated['supplier_id']) ? (string) $validated['supplier_id'] : null;

        $aging = $this->agingReportService->getApAging($asOfDate, $supplierId);
        $glApBalance = $this->financialReportService->getAccountBalanceByCode('2110', $asOfDate);
        $totalDue = $aging['summary']['total_due_cents'];
        $diff = $totalDue - $glApBalance;

        return ApiResponse::success(
            data: [
                'as_of_date' => $aging['as_of_date'],
                'summary' => $aging['summary'],
                'suppliers' => $aging['suppliers'],
                'bills' => $aging['bills'] ?? null,
                'gl_control_account_cents' => $glApBalance,
                'reconciliation_difference_cents' => $diff,
                'is_reconciled' => $diff === 0,
            ],
            message: 'AP aging report generated successfully.'
        );
    }

    public function stockValuation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'as_of_date' => ['nullable', 'date'],
            'warehouse_id' => ['nullable', 'string'],
            'category_id' => ['nullable', 'string'],
            'search' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
            'sort_field' => ['nullable', 'string'],
            'sort_direction' => ['nullable', 'in:asc,desc'],
        ]);

        $asOfDate = isset($validated['as_of_date']) ? (string) $validated['as_of_date'] : null;
        $warehouseId = isset($validated['warehouse_id']) && $validated['warehouse_id'] !== '' ? (string) $validated['warehouse_id'] : null;

        $summary = $this->inventoryCostingService->getValuationSummary($warehouseId);
        $glInventoryBalance = $this->financialReportService->getAccountBalanceByCode('1150', $asOfDate);
        $diff = $summary->totalValuationCents - $glInventoryBalance;

        $filters = [
            'warehouse_id' => $warehouseId,
            'category_id' => isset($validated['category_id']) && $validated['category_id'] !== '' ? (string) $validated['category_id'] : null,
            'search' => isset($validated['search']) ? (string) $validated['search'] : null,
        ];

        $sortDirection = ($validated['sort_direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $sort = [
            'field' => isset($validated['sort_field']) ? (string) $validated['sort_field'] : 'name',
            'direction' => $sortDirection,
        ];

        $perPage = isset($validated['per_page']) ? (int) $validated['per_page'] : 25;

        $paginated = $this->inventoryCostingService->paginateValuation($filters, $sort, $perPage);

        return ApiResponse::success(
            data: [
                'as_of_date' => $asOfDate !== null ? Carbon::parse($asOfDate)->toDateString() : Carbon::now()->toDateString(),
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
                'meta' => [
                    'current_page' => $paginated->currentPage(),
                    'last_page' => $paginated->lastPage(),
                    'per_page' => $paginated->perPage(),
                    'total' => $paginated->total(),
                ],
            ],
            message: 'Stock valuation report generated successfully.'
        );
    }

    public function stockMovement(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => ['nullable', 'string'],
            'product_id' => ['nullable', 'string'],
            'type' => ['nullable', 'string'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
            'sort_field' => ['nullable', 'string'],
            'sort_direction' => ['nullable', 'in:asc,desc'],
        ]);

        $filters = [
            'warehouse_id' => isset($validated['warehouse_id']) && $validated['warehouse_id'] !== '' ? (string) $validated['warehouse_id'] : null,
            'product_id' => isset($validated['product_id']) && $validated['product_id'] !== '' ? (string) $validated['product_id'] : null,
            'type' => isset($validated['type']) && $validated['type'] !== '' ? (string) $validated['type'] : null,
            'date_from' => isset($validated['date_from']) ? (string) $validated['date_from'] : null,
            'date_to' => isset($validated['date_to']) ? (string) $validated['date_to'] : null,
        ];

        $sortDirection = ($validated['sort_direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $sort = [
            'field' => isset($validated['sort_field']) ? (string) $validated['sort_field'] : 'created_at',
            'direction' => $sortDirection,
        ];

        $perPage = isset($validated['per_page']) ? (int) $validated['per_page'] : 25;

        $paginated = $this->stockService->paginateMovements($filters, $sort, $perPage);

        return ApiResponse::success(
            data: [
                'items' => StockMovementResource::collection($paginated->items()),
                'meta' => [
                    'current_page' => $paginated->currentPage(),
                    'last_page' => $paginated->lastPage(),
                    'per_page' => $paginated->perPage(),
                    'total' => $paginated->total(),
                ],
            ],
            message: 'Stock movements report generated successfully.'
        );
    }
}
