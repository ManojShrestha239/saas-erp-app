<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Inventory\Http\Requests\InventoryValuationFilterRequest;
use Modules\Inventory\Http\Resources\ProductValuationResource;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Services\InventoryCostingService;
use Shared\Responses\ApiResponse;

final class InventoryValuationController
{
    public function __construct(
        private readonly InventoryCostingService $costingService,
    ) {}

    public function index(InventoryValuationFilterRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Product::class);

        $perPage = min(max((int) $request->input('per_page', 25), 1), 100);

        /** @var string|null $search */
        $search = $request->input('search');
        /** @var string|null $categoryId */
        $categoryId = $request->input('category_id');
        /** @var string|null $warehouseId */
        $warehouseId = $request->input('warehouse_id');
        $inStockOnly = $request->boolean('in_stock_only');

        $sortField = (string) $request->input('sort_field', 'name');
        $sortDirection = strtolower((string) $request->input('sort_direction', 'asc')) === 'desc' ? 'desc' : 'asc';

        $paginator = $this->costingService->paginateValuation(
            filters: [
                'search' => $search,
                'category_id' => $categoryId,
                'warehouse_id' => $warehouseId,
                'in_stock_only' => $inStockOnly,
            ],
            sort: [
                'field' => $sortField,
                'direction' => $sortDirection,
            ],
            perPage: $perPage,
        );

        $summary = $this->costingService->getValuationSummary($warehouseId);

        $meta = [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];

        return ApiResponse::success(
            data: [
                'items' => ProductValuationResource::collection($paginator->items()),
                'summary' => $summary->toArray(),
            ],
            message: 'Stock valuation retrieved successfully.',
            meta: $meta,
        );
    }

    public function summary(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Product::class);

        /** @var string|null $warehouseId */
        $warehouseId = $request->query('warehouse_id');
        $summary = $this->costingService->getValuationSummary($warehouseId);

        return ApiResponse::success($summary->toArray(), 'Stock valuation summary retrieved successfully.');
    }

    public function history(Request $request, string $id): JsonResponse
    {
        Gate::authorize('viewAny', Product::class);

        $data = $this->costingService->getProductCostHistory($id);

        $historyItems = array_map(
            fn ($item): array => $item->toArray(),
            $data['history']
        );

        return ApiResponse::success([
            'product' => $data['product'],
            'history' => $historyItems,
        ], 'Product stock costing history retrieved successfully.');
    }
}
