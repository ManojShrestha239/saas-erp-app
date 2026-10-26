<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Inventory\DTOs\CreateProductData;
use Modules\Inventory\DTOs\UpdateProductData;
use Modules\Inventory\Models\Product;
use Shared\Exceptions\ApiException;

final class ProductService
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    public function create(CreateProductData $data): Product
    {
        return $this->db->connection('tenant')->transaction(function () use ($data): Product {
            $existing = Product::where('sku', $data->sku)->first();
            if ($existing !== null) {
                throw ApiException::conflict("A product with SKU '{$data->sku}' already exists.", 'SKU_ALREADY_EXISTS');
            }

            return Product::create($data->toArray());
        }, 3);
    }

    public function update(Product $product, UpdateProductData $data): Product
    {
        return $this->db->connection('tenant')->transaction(function () use ($product, $data): Product {
            /** @var Product $lockedProduct */
            $lockedProduct = Product::where('id', $product->id)->lockForUpdate()->firstOrFail();

            if ($data->sku !== null && $data->sku !== $lockedProduct->sku) {
                $existing = Product::where('sku', $data->sku)
                    ->where('id', '<>', $lockedProduct->id)
                    ->first();

                if ($existing !== null) {
                    throw ApiException::conflict("A product with SKU '{$data->sku}' already exists.", 'SKU_ALREADY_EXISTS');
                }
            }

            $lockedProduct->update($data->toFilteredArray());

            return $lockedProduct->fresh(['category', 'unitOfMeasure']) ?? $lockedProduct;
        }, 3);
    }

    public function delete(Product $product): void
    {
        $this->db->connection('tenant')->transaction(function () use ($product): void {
            /** @var Product $lockedProduct */
            $lockedProduct = Product::where('id', $product->id)->lockForUpdate()->firstOrFail();
            $lockedProduct->delete();
        }, 3);
    }

    public function findById(string $id): ?Product
    {
        return Product::with(['category', 'unitOfMeasure'])->find($id);
    }

    /**
     * @param  array{search?: ?string, category_id?: ?string, unit_of_measure_id?: ?string, is_active?: ?bool, tax_category?: ?string}  $filters
     * @param  array{field: string, direction: 'asc'|'desc'}  $sort
     * @return LengthAwarePaginator<int, Product>
     */
    public function paginate(array $filters, array $sort, int $perPage = 25): LengthAwarePaginator
    {
        $query = Product::with(['category', 'unitOfMeasure']);

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(function ($q) use ($term): void {
                $q->where('name', 'ilike', $term)
                    ->orWhere('sku', 'ilike', $term)
                    ->orWhere('barcode', 'ilike', $term);
            });
        }

        if (! empty($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        if (! empty($filters['unit_of_measure_id'])) {
            $query->where('unit_of_measure_id', $filters['unit_of_measure_id']);
        }

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        if (! empty($filters['tax_category'])) {
            $query->where('tax_category', $filters['tax_category']);
        }

        $query->orderBy($sort['field'], $sort['direction']);

        return $query->paginate($perPage);
    }
}
