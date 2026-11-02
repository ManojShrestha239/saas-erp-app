<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Inventory\DTOs\CostHistoryItemData;
use Modules\Inventory\DTOs\ProductValuationData;
use Modules\Inventory\DTOs\ValuationSummaryData;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\StockLevel;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\Warehouse;
use Shared\Exceptions\ApiException;

final class InventoryCostingService
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    /**
     * Calculate continuous moving weighted average cost in minor units (integer cents).
     *
     * @param  int  $currentQuantity  Current total stock quantity on hand before receipt.
     * @param  int  $currentUnitCostCents  Current product cost price in integer cents.
     * @param  int  $incomingQuantity  Incoming stock quantity (must be > 0).
     * @param  int  $incomingUnitCostCents  Purchase / incoming unit cost in integer cents.
     * @return int New moving weighted average cost in integer cents.
     */
    public function calculateMovingWeightedAverage(
        int $currentQuantity,
        int $currentUnitCostCents,
        int $incomingQuantity,
        int $incomingUnitCostCents,
    ): int {
        if ($incomingQuantity <= 0) {
            return max(0, $currentUnitCostCents);
        }

        // If current stock was 0 or negative, incoming cost sets the new moving average
        if ($currentQuantity <= 0) {
            return max(0, $incomingUnitCostCents);
        }

        // Existing stock valuation in minor units
        $existingValuationCents = $currentQuantity * $currentUnitCostCents;

        // Incoming stock valuation in minor units
        $incomingValuationCents = $incomingQuantity * $incomingUnitCostCents;

        $newTotalQuantity = $currentQuantity + $incomingQuantity;
        $totalValuationCents = $existingValuationCents + $incomingValuationCents;

        // Integer minor units with mathematical rounding
        return max(0, (int) round($totalValuationCents / $newTotalQuantity));
    }

    /**
     * Update product moving weighted average cost upon inbound receipt within an existing transaction.
     */
    public function updateProductCostOnReceipt(
        string $productId,
        int $incomingQuantity,
        int $incomingUnitCostCents,
    ): int {
        return $this->db->connection('tenant')->transaction(function () use ($productId, $incomingQuantity, $incomingUnitCostCents): int {
            /** @var Product|null $product */
            $product = Product::where('id', $productId)->lockForUpdate()->first();
            if ($product === null) {
                throw ApiException::notFound("Product with ID '{$productId}' not found.", 'PRODUCT_NOT_FOUND');
            }

            // Current total on-hand stock across all warehouses prior to receipt
            $currentTotalOnHand = (int) StockLevel::where('product_id', $productId)->sum('quantity_on_hand');

            $newAverageCostCents = $this->calculateMovingWeightedAverage(
                currentQuantity: $currentTotalOnHand,
                currentUnitCostCents: $product->cost_price_cents,
                incomingQuantity: $incomingQuantity,
                incomingUnitCostCents: $incomingUnitCostCents,
            );

            $product->update([
                'cost_price_cents' => $newAverageCostCents,
            ]);

            return $newAverageCostCents;
        }, 3);
    }

    /**
     * Compute summary valuation metrics across all products or for a specific warehouse.
     */
    public function getValuationSummary(?string $warehouseId = null): ValuationSummaryData
    {
        $products = Product::where('is_active', true)
            ->with(['stockLevels'])
            ->get();

        $totalValuationCents = 0;
        $totalUnitsOnHand = 0;
        $totalProductsCount = $products->count();
        $valuedProductsCount = 0;

        foreach ($products as $product) {
            $productQty = 0;
            foreach ($product->stockLevels as $level) {
                if ($warehouseId === null || $level->warehouse_id === $warehouseId) {
                    $productQty += $level->quantity_on_hand;
                }
            }

            if ($productQty > 0) {
                $valuedProductsCount++;
                $totalUnitsOnHand += $productQty;
                $totalValuationCents += $productQty * $product->cost_price_cents;
            }
        }

        return new ValuationSummaryData(
            totalValuationCents: $totalValuationCents,
            totalUnitsOnHand: $totalUnitsOnHand,
            totalProductsCount: $totalProductsCount,
            valuedProductsCount: $valuedProductsCount,
            currency: 'NPR',
        );
    }

    /**
     * Paginate product valuations with search, category/warehouse filters, and sorting.
     *
     * @param  array{search?: ?string, category_id?: ?string, warehouse_id?: ?string, in_stock_only?: ?bool}  $filters
     * @param  array{field: string, direction: 'asc'|'desc'}  $sort
     * @return LengthAwarePaginator<int, ProductValuationData>
     */
    public function paginateValuation(array $filters, array $sort, int $perPage = 25): LengthAwarePaginator
    {
        $warehouses = Warehouse::where('is_active', true)->get()->keyBy('id');

        $query = Product::with(['category', 'unitOfMeasure', 'stockLevels']);

        if (! empty($filters['search'])) {
            $term = '%'.trim((string) $filters['search']).'%';
            $query->where(function ($q) use ($term): void {
                $q->where('name', 'ilike', $term)
                    ->orWhere('sku', 'ilike', $term)
                    ->orWhere('barcode', 'ilike', $term);
            });
        }

        if (! empty($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        $allProducts = $query->get();

        /** @var list<ProductValuationData> $items */
        $items = [];

        foreach ($allProducts as $product) {
            $totalOnHand = 0;
            $warehouseBreakdown = [];

            foreach ($product->stockLevels as $level) {
                $qty = $level->quantity_on_hand;
                $warehouseModel = $warehouses->get($level->warehouse_id);
                $wName = $warehouseModel !== null ? $warehouseModel->name : 'Warehouse';

                if ($filters['warehouse_id'] ?? null) {
                    if ($level->warehouse_id === $filters['warehouse_id']) {
                        $totalOnHand += $qty;
                    }
                } else {
                    $totalOnHand += $qty;
                }

                $warehouseBreakdown[] = [
                    'warehouse_id' => $level->warehouse_id,
                    'warehouse_name' => $wName,
                    'quantity_on_hand' => $qty,
                    'valuation_cents' => $qty * $product->cost_price_cents,
                ];
            }

            if (! empty($filters['in_stock_only']) && $totalOnHand <= 0) {
                continue;
            }

            $totalValuationCents = $totalOnHand * $product->cost_price_cents;

            // Margin % = ((selling_price - cost_price) / selling_price) * 100
            $marginPercent = null;
            if ($product->selling_price_cents > 0) {
                $marginPercent = (int) round((($product->selling_price_cents - $product->cost_price_cents) / $product->selling_price_cents) * 100);
            }

            $items[] = new ProductValuationData(
                productId: $product->id,
                sku: $product->sku,
                name: $product->name,
                categoryName: $product->category?->name,
                unitOfMeasureName: $product->unitOfMeasure?->name,
                totalQuantityOnHand: $totalOnHand,
                movingAverageCostCents: $product->cost_price_cents,
                sellingPriceCents: $product->selling_price_cents,
                totalValuationCents: $totalValuationCents,
                marginPercent: $marginPercent,
                isActive: $product->is_active,
                warehouseBreakdown: $warehouseBreakdown,
            );
        }

        // Apply in-memory sort on computed fields
        $sortField = $sort['field'];
        $direction = strtolower($sort['direction']) === 'desc' ? 'desc' : 'asc';

        usort($items, function (ProductValuationData $a, ProductValuationData $b) use ($sortField, $direction): int {
            $valA = match ($sortField) {
                'sku' => $a->sku,
                'name' => $a->name,
                'total_quantity_on_hand' => $a->totalQuantityOnHand,
                'moving_average_cost_cents' => $a->movingAverageCostCents,
                'selling_price_cents' => $a->sellingPriceCents,
                'total_valuation_cents' => $a->totalValuationCents,
                default => $a->name,
            };

            $valB = match ($sortField) {
                'sku' => $b->sku,
                'name' => $b->name,
                'total_quantity_on_hand' => $b->totalQuantityOnHand,
                'moving_average_cost_cents' => $b->movingAverageCostCents,
                'selling_price_cents' => $b->sellingPriceCents,
                'total_valuation_cents' => $b->totalValuationCents,
                default => $b->name,
            };

            $cmp = is_string($valA) ? strcasecmp($valA, (string) $valB) : ($valA <=> $valB);

            return $direction === 'desc' ? -$cmp : $cmp;
        });

        $page = (int) request()->input('page', 1);
        $total = count($items);
        $offset = ($page - 1) * $perPage;
        $sliced = array_slice($items, $offset, $perPage);

        return new LengthAwarePaginator(
            $sliced,
            $total,
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    /**
     * Get historical audit trail of movements affecting stock costing for a product.
     *
     * @return array{product: array<string, mixed>, history: list<CostHistoryItemData>}
     */
    public function getProductCostHistory(string $productId): array
    {
        /** @var Product|null $product */
        $product = Product::with(['category', 'unitOfMeasure', 'stockLevels'])->find($productId);
        if ($product === null) {
            throw ApiException::notFound("Product with ID '{$productId}' not found.", 'PRODUCT_NOT_FOUND');
        }

        $movements = StockMovement::with('warehouse')
            ->where('product_id', $productId)
            ->orderBy('created_at', 'asc')
            ->get();

        $runningStock = 0;
        $runningAverageCost = 0;

        /** @var list<CostHistoryItemData> $history */
        $history = [];

        foreach ($movements as $m) {
            $qtyDelta = $m->quantity;
            $unitCost = $m->unit_cost_cents;

            $movementType = $m->type;
            $typeLabel = $movementType->label();
            $typeValue = $movementType->value;

            if ($movementType->isAddition()) {
                $runningAverageCost = $this->calculateMovingWeightedAverage(
                    currentQuantity: $runningStock,
                    currentUnitCostCents: $runningAverageCost,
                    incomingQuantity: abs($qtyDelta),
                    incomingUnitCostCents: $unitCost,
                );
            }

            $runningStock += $qtyDelta;
            $resultingValuation = $runningStock * $runningAverageCost;

            $warehouseName = $m->warehouse->name;

            $history[] = new CostHistoryItemData(
                movementId: $m->id,
                createdAt: $m->created_at->toIso8601String(),
                type: $typeValue,
                typeLabel: $typeLabel,
                warehouseName: $warehouseName,
                quantityDelta: $qtyDelta,
                movementUnitCostCents: $unitCost,
                sourceType: $m->source_type,
                sourceId: $m->source_id,
                notes: $m->notes,
                resultingAverageCostCents: $runningAverageCost,
                resultingValuationCents: max(0, $resultingValuation),
            );
        }

        return [
            'product' => [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'category_name' => $product->category?->name,
                'unit_of_measure_name' => $product->unitOfMeasure?->name,
                'current_moving_average_cost_cents' => $product->cost_price_cents,
                'total_quantity_on_hand' => (int) $product->stockLevels->sum('quantity_on_hand'),
                'total_valuation_cents' => (int) $product->stockLevels->sum('quantity_on_hand') * $product->cost_price_cents,
            ],
            // Return chronological history in reverse order (newest first) for UI display
            'history' => array_reverse($history),
        ];
    }
}
