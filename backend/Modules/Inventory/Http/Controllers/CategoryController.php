<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Inventory\Http\Requests\StoreCategoryRequest;
use Modules\Inventory\Http\Requests\UpdateCategoryRequest;
use Modules\Inventory\Http\Resources\CategoryResource;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Services\CategoryService;
use Shared\Exceptions\ApiException;
use Shared\Responses\ApiResponse;

final class CategoryController
{
    public function __construct(
        private readonly CategoryService $categoryService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Category::class);

        $perPage = min(max((int) $request->query('per_page', 25), 1), 100);

        /** @var string|null $search */
        $search = $request->query('search');
        $isActive = $request->has('is_active') ? $request->boolean('is_active') : null;

        $sortBy = (string) $request->query('sort_by', 'name');
        $sortDirection = strtolower((string) $request->query('sort_direction', 'asc')) === 'desc' ? 'desc' : 'asc';

        $allowedSorts = ['name', 'slug', 'created_at'];
        if (! in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'name';
        }

        $paginator = $this->categoryService->paginate(
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
            data: CategoryResource::collection($paginator->items()),
            message: 'Categories retrieved successfully.',
            meta: $meta,
        );
    }

    public function show(string $id): JsonResponse
    {
        $category = $this->categoryService->findById($id);
        if ($category === null) {
            throw ApiException::notFound("Category with ID '{$id}' not found.", 'CATEGORY_NOT_FOUND');
        }

        Gate::authorize('view', $category);

        return ApiResponse::success(
            data: new CategoryResource($category),
            message: 'Category retrieved successfully.',
        );
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        Gate::authorize('create', Category::class);

        $category = $this->categoryService->create($request->toDto());

        return ApiResponse::created(
            data: new CategoryResource($category),
            message: 'Category created successfully.',
        );
    }

    public function update(UpdateCategoryRequest $request, string $id): JsonResponse
    {
        $category = $this->categoryService->findById($id);
        if ($category === null) {
            throw ApiException::notFound("Category with ID '{$id}' not found.", 'CATEGORY_NOT_FOUND');
        }

        Gate::authorize('update', $category);

        $updated = $this->categoryService->update($category, $request->toDto());

        return ApiResponse::success(
            data: new CategoryResource($updated),
            message: 'Category updated successfully.',
        );
    }

    public function destroy(string $id): JsonResponse
    {
        $category = $this->categoryService->findById($id);
        if ($category === null) {
            throw ApiException::notFound("Category with ID '{$id}' not found.", 'CATEGORY_NOT_FOUND');
        }

        Gate::authorize('delete', $category);

        $this->categoryService->delete($category);

        return ApiResponse::success(
            data: null,
            message: 'Category deleted successfully.',
        );
    }
}
