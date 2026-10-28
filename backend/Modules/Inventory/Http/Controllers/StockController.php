<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Inventory\Http\Requests\RecordStockMovementRequest;
use Modules\Inventory\Http\Resources\LowStockResource;
use Modules\Inventory\Http\Resources\StockLevelResource;
use Modules\Inventory\Http\Resources\StockMovementResource;
use Modules\Inventory\Models\StockLevel;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Services\StockService;
use Shared\Exceptions\ApiException;
use Shared\Responses\ApiResponse;

final class StockController
{
    public function __construct(
        private readonly StockService $stockService,
    ) {}

    public function levels(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', StockLevel::class);

        $perPage = min(max((int) $request->query('per_page', 25), 1), 100);

        /** @var string|null $warehouseId */
        $warehouseId = $request->query('warehouse_id');
        /** @var string|null $productId */
        $productId = $request->query('product_id');
        /** @var string|null $search */
        $search = $request->query('search');

        $sortBy = (string) $request->query('sort_by', 'created_at');
        $sortDirection = strtolower((string) $request->query('sort_direction', 'desc')) === 'asc' ? 'asc' : 'desc';

        $allowedSorts = ['quantity_on_hand', 'quantity_reserved', 'created_at', 'updated_at'];
        if (! in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'created_at';
        }

        $paginator = $this->stockService->paginateLevels(
            filters: [
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'search' => $search,
            ],
            sort: [
                'field' => $sortBy,
                'direction' => $sortDirection,
            ],
            perPage: $perPage,
        );

        $meta = [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];

        return ApiResponse::success(
            data: StockLevelResource::collection($paginator->items()),
            message: 'Stock levels retrieved successfully.',
            meta: $meta,
        );
    }

    public function lowStock(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', StockLevel::class);

        $perPage = min(max((int) $request->query('per_page', 25), 1), 100);

        /** @var string|null $warehouseId */
        $warehouseId = $request->query('warehouse_id');
        /** @var string|null $search */
        $search = $request->query('search');

        $sortBy = (string) $request->query('sort_by', 'quantity_on_hand');
        $sortDirection = strtolower((string) $request->query('sort_direction', 'asc')) === 'desc' ? 'desc' : 'asc';

        $allowedSorts = ['quantity_on_hand', 'created_at', 'updated_at'];
        if (! in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'quantity_on_hand';
        }

        $paginator = $this->stockService->paginateLowStock(
            filters: [
                'warehouse_id' => $warehouseId,
                'search' => $search,
            ],
            sort: [
                'field' => $sortBy,
                'direction' => $sortDirection,
            ],
            perPage: $perPage,
        );

        $meta = [
            'page' => $paginator->currentPage(),
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];

        return ApiResponse::success(
            data: LowStockResource::collection($paginator->items()),
            message: 'Low stock items retrieved successfully.',
            meta: $meta,
        );
    }

    public function levelDetail(string $warehouseId, string $productId): JsonResponse
    {
        Gate::authorize('viewAny', StockLevel::class);

        $level = $this->stockService->getStockLevel($warehouseId, $productId);
        if ($level === null) {
            throw ApiException::notFound('Stock level record not found for this product and warehouse.', 'STOCK_LEVEL_NOT_FOUND');
        }

        return ApiResponse::success(
            data: new StockLevelResource($level),
            message: 'Stock level retrieved successfully.',
        );
    }

    public function movements(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', StockMovement::class);

        $perPage = min(max((int) $request->query('per_page', 25), 1), 100);

        /** @var string|null $warehouseId */
        $warehouseId = $request->query('warehouse_id');
        /** @var string|null $productId */
        $productId = $request->query('product_id');
        /** @var string|null $type */
        $type = $request->query('type');
        /** @var string|null $dateFrom */
        $dateFrom = $request->query('date_from');
        /** @var string|null $dateTo */
        $dateTo = $request->query('date_to');

        $sortBy = (string) $request->query('sort_by', 'created_at');
        $sortDirection = strtolower((string) $request->query('sort_direction', 'asc')) === 'asc' ? 'asc' : 'desc';

        $allowedSorts = ['created_at', 'quantity'];
        if (! in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'created_at';
        }

        $paginator = $this->stockService->paginateMovements(
            filters: [
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'type' => $type,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
            sort: [
                'field' => $sortBy,
                'direction' => $sortDirection,
            ],
            perPage: $perPage,
        );

        $meta = [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];

        return ApiResponse::success(
            data: StockMovementResource::collection($paginator->items()),
            message: 'Stock movements retrieved successfully.',
            meta: $meta,
        );
    }

    public function recordMovement(RecordStockMovementRequest $request): JsonResponse
    {
        Gate::authorize('recordMovement', StockMovement::class);

        $movement = $this->stockService->recordMovement($request->toDto());

        return ApiResponse::created(
            data: new StockMovementResource($movement),
            message: 'Stock movement recorded successfully.',
        );
    }
}
