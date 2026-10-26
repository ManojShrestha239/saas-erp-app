<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Inventory\Http\Requests\StoreProductRequest;
use Modules\Inventory\Http\Requests\UpdateProductRequest;
use Modules\Inventory\Http\Resources\ProductResource;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Services\ProductService;
use Shared\Exceptions\ApiException;
use Shared\Responses\ApiResponse;

final class ProductController
{
    public function __construct(
        private readonly ProductService $productService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Product::class);

        $perPage = min(max((int) $request->query('per_page', 25), 1), 100);

        /** @var string|null $search */
        $search = $request->query('search');
        /** @var string|null $categoryId */
        $categoryId = $request->query('category_id');
        /** @var string|null $unitOfMeasureId */
        $unitOfMeasureId = $request->query('unit_of_measure_id');
        /** @var string|null $taxCategory */
        $taxCategory = $request->query('tax_category');
        $isActive = $request->has('is_active') ? $request->boolean('is_active') : null;

        $sortBy = (string) $request->query('sort_by', 'name');
        $sortDirection = strtolower((string) $request->query('sort_direction', 'asc')) === 'desc' ? 'desc' : 'asc';

        $allowedSorts = ['name', 'sku', 'selling_price_cents', 'cost_price_cents', 'low_stock_threshold', 'created_at'];
        if (! in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'name';
        }

        $paginator = $this->productService->paginate(
            filters: [
                'search' => $search,
                'category_id' => $categoryId,
                'unit_of_measure_id' => $unitOfMeasureId,
                'tax_category' => $taxCategory,
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
            data: ProductResource::collection($paginator->items()),
            message: 'Products retrieved successfully.',
            meta: $meta,
        );
    }

    public function show(string $id): JsonResponse
    {
        $product = $this->productService->findById($id);
        if ($product === null) {
            throw ApiException::notFound("Product with ID '{$id}' not found.", 'PRODUCT_NOT_FOUND');
        }

        Gate::authorize('view', $product);

        return ApiResponse::success(
            data: new ProductResource($product),
            message: 'Product retrieved successfully.',
        );
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        Gate::authorize('create', Product::class);

        $product = $this->productService->create($request->toDto());
        $product->load(['category', 'unitOfMeasure']);

        return ApiResponse::created(
            data: new ProductResource($product),
            message: 'Product created successfully.',
        );
    }

    public function update(UpdateProductRequest $request, string $id): JsonResponse
    {
        $product = $this->productService->findById($id);
        if ($product === null) {
            throw ApiException::notFound("Product with ID '{$id}' not found.", 'PRODUCT_NOT_FOUND');
        }

        Gate::authorize('update', $product);

        $updated = $this->productService->update($product, $request->toDto());

        return ApiResponse::success(
            data: new ProductResource($updated),
            message: 'Product updated successfully.',
        );
    }

    public function destroy(string $id): JsonResponse
    {
        $product = $this->productService->findById($id);
        if ($product === null) {
            throw ApiException::notFound("Product with ID '{$id}' not found.", 'PRODUCT_NOT_FOUND');
        }

        Gate::authorize('delete', $product);

        $this->productService->delete($product);

        return ApiResponse::success(
            data: null,
            message: 'Product deleted successfully.',
        );
    }
}
