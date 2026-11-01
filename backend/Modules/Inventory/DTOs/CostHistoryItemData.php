<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

final readonly class CostHistoryItemData
{
    public function __construct(
        public string $movementId,
        public string $createdAt,
        public string $type,
        public string $typeLabel,
        public string $warehouseName,
        public int $quantityDelta,
        public int $movementUnitCostCents,
        public ?string $sourceType,
        public ?string $sourceId,
        public ?string $notes,
        public int $resultingAverageCostCents,
        public int $resultingValuationCents,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'movement_id' => $this->movementId,
            'created_at' => $this->createdAt,
            'type' => $this->type,
            'type_label' => $this->typeLabel,
            'warehouse_name' => $this->warehouseName,
            'quantity_delta' => $this->quantityDelta,
            'movement_unit_cost_cents' => $this->movementUnitCostCents,
            'source_type' => $this->sourceType,
            'source_id' => $this->sourceId,
            'notes' => $this->notes,
            'resulting_average_cost_cents' => $this->resultingAverageCostCents,
            'resulting_valuation_cents' => $this->resultingValuationCents,
        ];
    }
}
