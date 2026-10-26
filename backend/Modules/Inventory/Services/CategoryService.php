<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Inventory\DTOs\CreateCategoryData;
use Modules\Inventory\DTOs\UpdateCategoryData;
use Modules\Inventory\Models\Category;
use Shared\Exceptions\ApiException;

final class CategoryService
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    public function create(CreateCategoryData $data): Category
    {
        return $this->db->connection('tenant')->transaction(function () use ($data): Category {
            $existing = Category::where('slug', $data->slug)->first();
            if ($existing !== null) {
                throw ApiException::conflict("A category with slug '{$data->slug}' already exists.", 'CATEGORY_SLUG_EXISTS');
            }

            return Category::create($data->toArray());
        }, 3);
    }

    public function update(Category $category, UpdateCategoryData $data): Category
    {
        return $this->db->connection('tenant')->transaction(function () use ($category, $data): Category {
            /** @var Category $lockedCategory */
            $lockedCategory = Category::where('id', $category->id)->lockForUpdate()->firstOrFail();

            if ($data->slug !== null && $data->slug !== $lockedCategory->slug) {
                $existing = Category::where('slug', $data->slug)
                    ->where('id', '<>', $lockedCategory->id)
                    ->first();

                if ($existing !== null) {
                    throw ApiException::conflict("A category with slug '{$data->slug}' already exists.", 'CATEGORY_SLUG_EXISTS');
                }
            }

            $lockedCategory->update($data->toFilteredArray());

            return $lockedCategory->fresh() ?? $lockedCategory;
        }, 3);
    }

    public function delete(Category $category): void
    {
        $this->db->connection('tenant')->transaction(function () use ($category): void {
            /** @var Category $lockedCategory */
            $lockedCategory = Category::where('id', $category->id)->lockForUpdate()->firstOrFail();
            $lockedCategory->delete();
        }, 3);
    }

    public function findById(string $id): ?Category
    {
        return Category::find($id);
    }

    /**
     * @param  array{search?: ?string, is_active?: ?bool}  $filters
     * @param  array{field: string, direction: 'asc'|'desc'}  $sort
     * @return LengthAwarePaginator<int, Category>
     */
    public function paginate(array $filters, array $sort, int $perPage = 25): LengthAwarePaginator
    {
        $query = Category::query();

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(function ($q) use ($term): void {
                $q->where('name', 'ilike', $term)
                    ->orWhere('slug', 'ilike', $term);
            });
        }

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        $query->orderBy($sort['field'], $sort['direction']);

        return $query->paginate($perPage);
    }
}
