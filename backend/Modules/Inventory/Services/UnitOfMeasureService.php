<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Inventory\DTOs\CreateUnitOfMeasureData;
use Modules\Inventory\DTOs\UpdateUnitOfMeasureData;
use Modules\Inventory\Models\UnitOfMeasure;
use Shared\Exceptions\ApiException;

final class UnitOfMeasureService
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    public function create(CreateUnitOfMeasureData $data): UnitOfMeasure
    {
        return $this->db->connection('tenant')->transaction(function () use ($data): UnitOfMeasure {
            $existing = UnitOfMeasure::where('symbol', $data->symbol)->first();
            if ($existing !== null) {
                throw ApiException::conflict("A unit of measure with symbol '{$data->symbol}' already exists.", 'UOM_SYMBOL_EXISTS');
            }

            return UnitOfMeasure::create($data->toArray());
        }, 3);
    }

    public function update(UnitOfMeasure $uom, UpdateUnitOfMeasureData $data): UnitOfMeasure
    {
        return $this->db->connection('tenant')->transaction(function () use ($uom, $data): UnitOfMeasure {
            /** @var UnitOfMeasure $lockedUom */
            $lockedUom = UnitOfMeasure::where('id', $uom->id)->lockForUpdate()->firstOrFail();

            if ($data->symbol !== null && $data->symbol !== $lockedUom->symbol) {
                $existing = UnitOfMeasure::where('symbol', $data->symbol)
                    ->where('id', '<>', $lockedUom->id)
                    ->first();

                if ($existing !== null) {
                    throw ApiException::conflict("A unit of measure with symbol '{$data->symbol}' already exists.", 'UOM_SYMBOL_EXISTS');
                }
            }

            $lockedUom->update($data->toFilteredArray());

            return $lockedUom->fresh() ?? $lockedUom;
        }, 3);
    }

    public function delete(UnitOfMeasure $uom): void
    {
        $this->db->connection('tenant')->transaction(function () use ($uom): void {
            /** @var UnitOfMeasure $lockedUom */
            $lockedUom = UnitOfMeasure::where('id', $uom->id)->lockForUpdate()->firstOrFail();
            $lockedUom->delete();
        }, 3);
    }

    public function findById(string $id): ?UnitOfMeasure
    {
        return UnitOfMeasure::find($id);
    }

    /**
     * @param  array{search?: ?string, is_active?: ?bool}  $filters
     * @param  array{field: string, direction: 'asc'|'desc'}  $sort
     * @return LengthAwarePaginator<int, UnitOfMeasure>
     */
    public function paginate(array $filters, array $sort, int $perPage = 25): LengthAwarePaginator
    {
        $query = UnitOfMeasure::query();

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(function ($q) use ($term): void {
                $q->where('name', 'ilike', $term)
                    ->orWhere('symbol', 'ilike', $term);
            });
        }

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        $query->orderBy($sort['field'], $sort['direction']);

        return $query->paginate($perPage);
    }
}
