<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Inventory\DTOs\CreateStockAdjustmentData;
use Modules\Inventory\DTOs\RecordStockMovementData;
use Modules\Inventory\Enums\StockMovementType;
use Modules\Inventory\Events\StockAdjusted;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\StockAdjustment;
use Modules\Inventory\Models\StockAdjustmentItem;
use Modules\Inventory\Models\StockLevel;
use Modules\Inventory\Models\Warehouse;
use Shared\Enums\DocumentType;
use Shared\Exceptions\ApiException;
use Shared\Services\DocumentNumberingService;
use Shared\Tenancy\TenantContext;

final class StockAdjustmentService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly StockService $stockService,
        private readonly DocumentNumberingService $documentNumberingService,
    ) {}

    public function create(CreateStockAdjustmentData $data): StockAdjustment
    {
        return $this->db->connection('tenant')->transaction(function () use ($data): StockAdjustment {
            /** @var Warehouse|null $warehouse */
            $warehouse = Warehouse::find($data->warehouseId);
            if ($warehouse === null) {
                throw ApiException::notFound("Warehouse with ID '{$data->warehouseId}' not found.", 'WAREHOUSE_NOT_FOUND');
            }
            if (! $warehouse->is_active) {
                throw ApiException::badRequest("Warehouse '{$warehouse->name}' is inactive.", 'WAREHOUSE_INACTIVE');
            }

            if (empty(trim($data->reason))) {
                throw ApiException::unprocessable('A mandatory reason is required for manual stock adjustments.', 'REASON_REQUIRED');
            }

            if (empty($data->items)) {
                throw ApiException::unprocessable('At least one item is required for a stock adjustment.', 'ITEMS_REQUIRED');
            }

            $adjNumber = $this->documentNumberingService->generateNext(
                type: DocumentType::StockAdjustment,
                year: (int) now()->format('Y'),
            );

            /** @var StockAdjustment $adjustment */
            $adjustment = StockAdjustment::create([
                'adjustment_number' => $adjNumber,
                'warehouse_id' => $data->warehouseId,
                'reason' => trim($data->reason),
                'user_id' => $data->userId,
                'status' => 'posted',
                'total_cost_impact_cents' => 0,
            ]);

            $totalCostImpactCents = 0;
            /** @var list<array{product_id: string, quantity_before: int, quantity_delta: int, quantity_after: int, unit_cost_cents: int}> $eventItems */
            $eventItems = [];

            foreach ($data->items as $item) {
                if ($item->quantityDelta === 0) {
                    continue;
                }

                /** @var Product|null $product */
                $product = Product::find($item->productId);
                if ($product === null) {
                    throw ApiException::notFound("Product with ID '{$item->productId}' not found.", 'PRODUCT_NOT_FOUND');
                }

                // Read current stock before adjustment
                $level = StockLevel::where('warehouse_id', $data->warehouseId)
                    ->where('product_id', $item->productId)
                    ->lockForUpdate()
                    ->first();

                $quantityBefore = $level !== null ? $level->quantity_on_hand : 0;
                $unitCost = $item->unitCostCents ?? $product->cost_price_cents;

                $movementType = $item->quantityDelta > 0
                    ? StockMovementType::AdjustmentIn
                    : StockMovementType::AdjustmentOut;

                // Record movement and update stock level atomically
                $this->stockService->recordMovement(new RecordStockMovementData(
                    warehouseId: $data->warehouseId,
                    productId: $item->productId,
                    type: $movementType,
                    quantity: abs($item->quantityDelta),
                    unitCostCents: $unitCost,
                    sourceType: 'stock_adjustment',
                    sourceId: $adjustment->id,
                    userId: $data->userId,
                    notes: $data->reason,
                ));

                $quantityAfter = $quantityBefore + $item->quantityDelta;
                $lineCostImpact = $item->quantityDelta * $unitCost;
                $totalCostImpactCents += $lineCostImpact;

                StockAdjustmentItem::create([
                    'stock_adjustment_id' => $adjustment->id,
                    'product_id' => $item->productId,
                    'quantity_before' => $quantityBefore,
                    'quantity_delta' => $item->quantityDelta,
                    'quantity_after' => $quantityAfter,
                    'unit_cost_cents' => $unitCost,
                ]);

                $eventItems[] = [
                    'product_id' => $item->productId,
                    'quantity_before' => $quantityBefore,
                    'quantity_delta' => $item->quantityDelta,
                    'quantity_after' => $quantityAfter,
                    'unit_cost_cents' => $unitCost,
                ];
            }

            $adjustment->update([
                'total_cost_impact_cents' => $totalCostImpactCents,
            ]);

            // Dispatch domain event (Phase 5 will attach ledger posting listener)
            $tenantId = TenantContext::get()?->id;
            StockAdjusted::dispatch(
                $adjustment->id,
                $adjustment->warehouse_id,
                $adjustment->reason,
                $totalCostImpactCents,
                $eventItems,
                $tenantId !== null ? (string) $tenantId : null
            );

            return $adjustment->load(['warehouse', 'items.product']);
        }, 3);
    }

    public function findById(string $id): ?StockAdjustment
    {
        return StockAdjustment::with(['warehouse', 'items.product'])->find($id);
    }

    /**
     * @param  array{warehouse_id?: ?string, search?: ?string, date_from?: ?string, date_to?: ?string}  $filters
     * @param  array{field: string, direction: 'asc'|'desc'}  $sort
     * @return LengthAwarePaginator<int, StockAdjustment>
     */
    public function paginate(array $filters, array $sort, int $perPage = 25): LengthAwarePaginator
    {
        $query = StockAdjustment::with(['warehouse', 'items.product']);

        if (! empty($filters['warehouse_id'])) {
            $query->where('warehouse_id', $filters['warehouse_id']);
        }

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(function ($q) use ($term): void {
                $q->where('adjustment_number', 'ilike', $term)
                    ->orWhere('reason', 'ilike', $term);
            });
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
}
