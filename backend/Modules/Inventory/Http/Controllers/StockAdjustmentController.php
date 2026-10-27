<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Inventory\Http\Requests\StoreStockAdjustmentRequest;
use Modules\Inventory\Http\Resources\StockAdjustmentResource;
use Modules\Inventory\Models\StockAdjustment;
use Modules\Inventory\Services\StockAdjustmentService;
use Shared\Exceptions\ApiException;
use Shared\Responses\ApiResponse;

final class StockAdjustmentController
{
    public function __construct(
        private readonly StockAdjustmentService $adjustmentService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', StockAdjustment::class);

        $perPage = min(max((int) $request->query('per_page', 25), 1), 100);

        /** @var string|null $warehouseId */
        $warehouseId = $request->query('warehouse_id');
        /** @var string|null $search */
        $search = $request->query('search');
        /** @var string|null $dateFrom */
        $dateFrom = $request->query('date_from');
        /** @var string|null $dateTo */
        $dateTo = $request->query('date_to');

        $sortBy = (string) $request->query('sort_by', 'created_at');
        $sortDirection = strtolower((string) $request->query('sort_direction', 'desc')) === 'asc' ? 'asc' : 'desc';

        $allowedSorts = ['created_at', 'adjustment_number', 'total_cost_impact_cents'];
        if (! in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'created_at';
        }

        $paginator = $this->adjustmentService->paginate(
            filters: [
                'warehouse_id' => $warehouseId,
                'search' => $search,
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
            data: StockAdjustmentResource::collection($paginator->items()),
            message: 'Stock adjustments retrieved successfully.',
            meta: $meta,
        );
    }

    public function show(string $id): JsonResponse
    {
        $adjustment = $this->adjustmentService->findById($id);
        if ($adjustment === null) {
            throw ApiException::notFound('Stock adjustment not found.', 'STOCK_ADJUSTMENT_NOT_FOUND');
        }

        Gate::authorize('view', $adjustment);

        return ApiResponse::success(
            data: new StockAdjustmentResource($adjustment),
            message: 'Stock adjustment retrieved successfully.',
        );
    }

    public function store(StoreStockAdjustmentRequest $request): JsonResponse
    {
        Gate::authorize('create', StockAdjustment::class);

        $adjustment = $this->adjustmentService->create($request->toDto());

        return ApiResponse::created(
            data: new StockAdjustmentResource($adjustment),
            message: 'Stock adjustment created successfully.',
        );
    }
}
