<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Inventory\DTOs\CreateWarehouseData;
use Modules\Inventory\DTOs\UpdateWarehouseData;
use Modules\Inventory\Models\StockLevel;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\Warehouse;
use Shared\Exceptions\ApiException;

final class WarehouseService
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    public function create(CreateWarehouseData $data): Warehouse
    {
        return $this->db->connection('tenant')->transaction(function () use ($data): Warehouse {
            $existing = Warehouse::where('code', $data->code)->first();
            if ($existing !== null) {
                throw ApiException::conflict("A warehouse with code '{$data->code}' already exists.", 'WAREHOUSE_CODE_EXISTS');
            }

            if ($data->isDefault) {
                Warehouse::where('is_default', true)->update(['is_default' => false]);
            }

            return Warehouse::create($data->toArray());
        }, 3);
    }

    public function update(Warehouse $warehouse, UpdateWarehouseData $data): Warehouse
    {
        return $this->db->connection('tenant')->transaction(function () use ($warehouse, $data): Warehouse {
            /** @var Warehouse $lockedWarehouse */
            $lockedWarehouse = Warehouse::where('id', $warehouse->id)->lockForUpdate()->firstOrFail();

            if ($data->code !== null && $data->code !== $lockedWarehouse->code) {
                $existing = Warehouse::where('code', $data->code)
                    ->where('id', '<>', $lockedWarehouse->id)
                    ->first();

                if ($existing !== null) {
                    throw ApiException::conflict("A warehouse with code '{$data->code}' already exists.", 'WAREHOUSE_CODE_EXISTS');
                }
            }

            if ($data->isDefault === true) {
                Warehouse::where('is_default', true)
                    ->where('id', '<>', $lockedWarehouse->id)
                    ->update(['is_default' => false]);
            }

            $lockedWarehouse->update($data->toFilteredArray());

            return $lockedWarehouse->fresh() ?? $lockedWarehouse;
        }, 3);
    }

    public function delete(Warehouse $warehouse): void
    {
        $this->db->connection('tenant')->transaction(function () use ($warehouse): void {
            /** @var Warehouse $lockedWarehouse */
            $lockedWarehouse = Warehouse::where('id', $warehouse->id)->lockForUpdate()->firstOrFail();

            $hasMovements = StockMovement::where('warehouse_id', $lockedWarehouse->id)->exists();
            if ($hasMovements) {
                throw ApiException::badRequest(
                    'Cannot delete warehouse with existing stock history.',
                    'WAREHOUSE_HAS_MOVEMENTS'
                );
            }

            $hasStock = StockLevel::where('warehouse_id', $lockedWarehouse->id)
                ->where('quantity_on_hand', '>', 0)
                ->exists();
            if ($hasStock) {
                throw ApiException::badRequest(
                    'Cannot delete warehouse with active stock on hand.',
                    'WAREHOUSE_HAS_STOCK'
                );
            }

            $lockedWarehouse->delete();
        }, 3);
    }

    public function findById(string $id): ?Warehouse
    {
        return Warehouse::find($id);
    }

    public function getDefault(): ?Warehouse
    {
        return Warehouse::where('is_default', true)->first()
            ?? Warehouse::where('is_active', true)->orderBy('created_at')->first();
    }

    /**
     * @param  array{search?: ?string, is_active?: ?bool}  $filters
     * @param  array{field: string, direction: 'asc'|'desc'}  $sort
     * @return LengthAwarePaginator<int, Warehouse>
     */
    public function paginate(array $filters, array $sort, int $perPage = 25): LengthAwarePaginator
    {
        $query = Warehouse::query();

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(function ($q) use ($term): void {
                $q->where('name', 'ilike', $term)
                    ->orWhere('code', 'ilike', $term);
            });
        }

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        $query->orderBy($sort['field'], $sort['direction']);

        return $query->paginate($perPage);
    }
}
