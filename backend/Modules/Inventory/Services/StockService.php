<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Inventory\DTOs\RecordStockMovementData;
use Modules\Inventory\Events\LowStockDetected;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\StockLevel;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\Warehouse;
use Shared\Exceptions\ApiException;
use Shared\Tenancy\TenantContext;

final class StockService
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    public function recordMovement(RecordStockMovementData $data): StockMovement
    {
        return $this->db->connection('tenant')->transaction(function () use ($data): StockMovement {
            /** @var Warehouse|null $warehouse */
            $warehouse = Warehouse::find($data->warehouseId);
            if ($warehouse === null) {
                throw ApiException::notFound("Warehouse with ID '{$data->warehouseId}' not found.", 'WAREHOUSE_NOT_FOUND');
            }
            if (! $warehouse->is_active) {
                throw ApiException::badRequest("Warehouse '{$warehouse->name}' is inactive.", 'WAREHOUSE_INACTIVE');
            }

            /** @var Product|null $product */
            $product = Product::find($data->productId);
            if ($product === null) {
                throw ApiException::notFound("Product with ID '{$data->productId}' not found.", 'PRODUCT_NOT_FOUND');
            }
            if (! $product->is_active) {
                throw ApiException::badRequest("Product '{$product->name}' is inactive.", 'PRODUCT_INACTIVE');
            }

            // Ensure StockLevel row exists
            StockLevel::firstOrCreate(
                [
                    'warehouse_id' => $data->warehouseId,
                    'product_id' => $data->productId,
                ],
                [
                    'quantity_on_hand' => 0,
                    'quantity_reserved' => 0,
                ]
            );

            // Pessimistic lock on the stock level row
            /** @var StockLevel $stockLevel */
            $stockLevel = StockLevel::where('warehouse_id', $data->warehouseId)
                ->where('product_id', $data->productId)
                ->lockForUpdate()
                ->firstOrFail();

            $signedQuantity = $data->getSignedQuantity();
            $newOnHand = $stockLevel->quantity_on_hand + $signedQuantity;

            if ($newOnHand < 0 && ! $this->allowsNegativeStock()) {
                throw ApiException::badRequest(
                    "Insufficient stock for product '{$product->sku}' in warehouse '{$warehouse->name}'. On hand: {$stockLevel->quantity_on_hand}, Requested: {$signedQuantity}.",
                    'INSUFFICIENT_STOCK'
                );
            }

            // Update cached stock level
            $stockLevel->quantity_on_hand = $newOnHand;
            $stockLevel->save();

            // Append stock movement ledger entry
            $movement = StockMovement::create([
                'warehouse_id' => $data->warehouseId,
                'product_id' => $data->productId,
                'type' => $data->type,
                'quantity' => $signedQuantity,
                'unit_cost_cents' => $data->unitCostCents > 0 ? $data->unitCostCents : $product->cost_price_cents,
                'source_type' => $data->sourceType,
                'source_id' => $data->sourceId,
                'user_id' => $data->userId,
                'notes' => $data->notes,
                'created_at' => now(),
            ]);

            if ($product->low_stock_threshold > 0 && $newOnHand <= $product->low_stock_threshold) {
                $tenantId = app()->bound(TenantContext::class) ? (string) app(TenantContext::class)->id : '';

                event(new LowStockDetected(
                    tenantId: $tenantId,
                    productId: $product->id,
                    productName: $product->name,
                    sku: $product->sku,
                    warehouseId: $warehouse->id,
                    warehouseName: $warehouse->name,
                    quantityOnHand: $newOnHand,
                    lowStockThreshold: $product->low_stock_threshold,
                ));
            }

            return $movement->load(['warehouse', 'product']);
        }, 3);
    }

    public function getStockLevel(string $warehouseId, string $productId): ?StockLevel
    {
        return StockLevel::with(['warehouse', 'product'])
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->first();
    }

    public function getProductTotalStock(string $productId): int
    {
        return (int) StockLevel::where('product_id', $productId)->sum('quantity_on_hand');
    }

    /**
     * @param  array{warehouse_id?: ?string, product_id?: ?string, search?: ?string}  $filters
     * @param  array{field: string, direction: 'asc'|'desc'}  $sort
     * @return LengthAwarePaginator<int, StockLevel>
     */
    public function paginateLevels(array $filters, array $sort, int $perPage = 25): LengthAwarePaginator
    {
        $query = StockLevel::with(['warehouse', 'product.category', 'product.unitOfMeasure']);

        if (! empty($filters['warehouse_id'])) {
            $query->where('warehouse_id', $filters['warehouse_id']);
        }

        if (! empty($filters['product_id'])) {
            $query->where('product_id', $filters['product_id']);
        }

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->whereHas('product', function ($q) use ($term): void {
                $q->where('name', 'ilike', $term)
                    ->orWhere('sku', 'ilike', $term)
                    ->orWhere('barcode', 'ilike', $term);
            });
        }

        $query->orderBy($sort['field'], $sort['direction']);

        return $query->paginate($perPage);
    }

    /**
     * @param  array{warehouse_id?: ?string, search?: ?string}  $filters
     * @param  array{field: string, direction: 'asc'|'desc'}  $sort
     * @return LengthAwarePaginator<int, StockLevel>
     */
    public function paginateLowStock(array $filters, array $sort, int $perPage = 25): LengthAwarePaginator
    {
        $query = StockLevel::with(['warehouse', 'product.category', 'product.unitOfMeasure'])
            ->whereHas('product', function ($q): void {
                $q->where('low_stock_threshold', '>', 0)
                    ->whereColumn('stock_levels.quantity_on_hand', '<=', 'products.low_stock_threshold')
                    ->where('is_active', true);
            });

        if (! empty($filters['warehouse_id'])) {
            $query->where('warehouse_id', $filters['warehouse_id']);
        }

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->whereHas('product', function ($q) use ($term): void {
                $q->where('name', 'ilike', $term)
                    ->orWhere('sku', 'ilike', $term)
                    ->orWhere('barcode', 'ilike', $term);
            });
        }

        $query->orderBy($sort['field'], $sort['direction']);

        return $query->paginate($perPage);
    }

    /**
     * @param  array{warehouse_id?: ?string, product_id?: ?string, type?: ?string, date_from?: ?string, date_to?: ?string}  $filters
     * @param  array{field: string, direction: 'asc'|'desc'}  $sort
     * @return LengthAwarePaginator<int, StockMovement>
     */
    public function paginateMovements(array $filters, array $sort, int $perPage = 25): LengthAwarePaginator
    {
        $query = StockMovement::with(['warehouse', 'product']);

        if (! empty($filters['warehouse_id'])) {
            $query->where('warehouse_id', $filters['warehouse_id']);
        }

        if (! empty($filters['product_id'])) {
            $query->where('product_id', $filters['product_id']);
        }

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (! empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        $query->orderBy($sort['field'], $sort['direction']);

        return $query->paginate($perPage);
    }

    public function allowsNegativeStock(): bool
    {
        $val = $this->db->connection('tenant')
            ->table('settings')
            ->where('key', 'allow_negative_stock')
            ->value('value');

        return in_array(strtolower((string) $val), ['true', '1', 'yes', 'on'], true);
    }
}
