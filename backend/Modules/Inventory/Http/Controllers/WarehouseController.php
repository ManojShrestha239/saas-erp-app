<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Inventory\Http\Requests\StoreWarehouseRequest;
use Modules\Inventory\Http\Requests\UpdateWarehouseRequest;
use Modules\Inventory\Http\Resources\WarehouseResource;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\WarehouseService;
use Shared\Exceptions\ApiException;
use Shared\Responses\ApiResponse;

final class WarehouseController
{
    public function __construct(
        private readonly WarehouseService $warehouseService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Warehouse::class);

        $perPage = min(max((int) $request->query('per_page', 25), 1), 100);

        /** @var string|null $search */
        $search = $request->query('search');
        $isActive = $request->has('is_active') ? $request->boolean('is_active') : null;

        $sortBy = (string) $request->query('sort_by', 'name');
        $sortDirection = strtolower((string) $request->query('sort_direction', 'asc')) === 'desc' ? 'desc' : 'asc';

        $allowedSorts = ['name', 'code', 'created_at', 'is_default'];
        if (! in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'name';
        }

        $paginator = $this->warehouseService->paginate(
            filters: [
                'search' => $search,
                'is_active' => $isActive,
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
            data: WarehouseResource::collection($paginator->items()),
            message: 'Warehouses retrieved successfully.',
            meta: $meta,
        );
    }

    public function show(string $id): JsonResponse
    {
        $warehouse = $this->warehouseService->findById($id);
        if ($warehouse === null) {
            throw ApiException::notFound("Warehouse with ID '{$id}' not found.", 'WAREHOUSE_NOT_FOUND');
        }

        Gate::authorize('view', $warehouse);

        return ApiResponse::success(
            data: new WarehouseResource($warehouse),
            message: 'Warehouse retrieved successfully.',
        );
    }

    public function store(StoreWarehouseRequest $request): JsonResponse
    {
        Gate::authorize('create', Warehouse::class);

        $warehouse = $this->warehouseService->create($request->toDto());

        return ApiResponse::created(
            data: new WarehouseResource($warehouse),
            message: 'Warehouse created successfully.',
        );
    }

    public function update(UpdateWarehouseRequest $request, string $id): JsonResponse
    {
        $warehouse = $this->warehouseService->findById($id);
        if ($warehouse === null) {
            throw ApiException::notFound("Warehouse with ID '{$id}' not found.", 'WAREHOUSE_NOT_FOUND');
        }

        Gate::authorize('update', $warehouse);

        $updated = $this->warehouseService->update($warehouse, $request->toDto());

        return ApiResponse::success(
            data: new WarehouseResource($updated),
            message: 'Warehouse updated successfully.',
        );
    }

    public function destroy(string $id): JsonResponse
    {
        $warehouse = $this->warehouseService->findById($id);
        if ($warehouse === null) {
            throw ApiException::notFound("Warehouse with ID '{$id}' not found.", 'WAREHOUSE_NOT_FOUND');
        }

        Gate::authorize('delete', $warehouse);

        $this->warehouseService->delete($warehouse);

        return ApiResponse::success(
            data: null,
            message: 'Warehouse deleted successfully.',
        );
    }
}
