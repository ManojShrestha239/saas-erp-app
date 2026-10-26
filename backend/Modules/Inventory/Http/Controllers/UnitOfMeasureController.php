<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Inventory\Http\Requests\StoreUnitOfMeasureRequest;
use Modules\Inventory\Http\Requests\UpdateUnitOfMeasureRequest;
use Modules\Inventory\Http\Resources\UnitOfMeasureResource;
use Modules\Inventory\Models\UnitOfMeasure;
use Modules\Inventory\Services\UnitOfMeasureService;
use Shared\Exceptions\ApiException;
use Shared\Responses\ApiResponse;

final class UnitOfMeasureController
{
    public function __construct(
        private readonly UnitOfMeasureService $uomService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', UnitOfMeasure::class);

        $perPage = min(max((int) $request->query('per_page', 25), 1), 100);

        /** @var string|null $search */
        $search = $request->query('search');
        $isActive = $request->has('is_active') ? $request->boolean('is_active') : null;

        $sortBy = (string) $request->query('sort_by', 'name');
        $sortDirection = strtolower((string) $request->query('sort_direction', 'asc')) === 'desc' ? 'desc' : 'asc';

        $allowedSorts = ['name', 'symbol', 'created_at'];
        if (! in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'name';
        }

        $paginator = $this->uomService->paginate(
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
            data: UnitOfMeasureResource::collection($paginator->items()),
            message: 'Units of measure retrieved successfully.',
            meta: $meta,
        );
    }

    public function show(string $id): JsonResponse
    {
        $uom = $this->uomService->findById($id);
        if ($uom === null) {
            throw ApiException::notFound("Unit of measure with ID '{$id}' not found.", 'UOM_NOT_FOUND');
        }

        Gate::authorize('view', $uom);

        return ApiResponse::success(
            data: new UnitOfMeasureResource($uom),
            message: 'Unit of measure retrieved successfully.',
        );
    }

    public function store(StoreUnitOfMeasureRequest $request): JsonResponse
    {
        Gate::authorize('create', UnitOfMeasure::class);

        $uom = $this->uomService->create($request->toDto());

        return ApiResponse::created(
            data: new UnitOfMeasureResource($uom),
            message: 'Unit of measure created successfully.',
        );
    }

    public function update(UpdateUnitOfMeasureRequest $request, string $id): JsonResponse
    {
        $uom = $this->uomService->findById($id);
        if ($uom === null) {
            throw ApiException::notFound("Unit of measure with ID '{$id}' not found.", 'UOM_NOT_FOUND');
        }

        Gate::authorize('update', $uom);

        $updated = $this->uomService->update($uom, $request->toDto());

        return ApiResponse::success(
            data: new UnitOfMeasureResource($updated),
            message: 'Unit of measure updated successfully.',
        );
    }

    public function destroy(string $id): JsonResponse
    {
        $uom = $this->uomService->findById($id);
        if ($uom === null) {
            throw ApiException::notFound("Unit of measure with ID '{$id}' not found.", 'UOM_NOT_FOUND');
        }

        Gate::authorize('delete', $uom);

        $this->uomService->delete($uom);

        return ApiResponse::success(
            data: null,
            message: 'Unit of measure deleted successfully.',
        );
    }
}
