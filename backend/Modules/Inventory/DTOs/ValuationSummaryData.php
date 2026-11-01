<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

final readonly class ValuationSummaryData
{
    public function __construct(
        public int $totalValuationCents,
        public int $totalUnitsOnHand,
        public int $totalProductsCount,
        public int $valuedProductsCount,
        public string $currency = 'NPR',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'total_valuation_cents' => $this->totalValuationCents,
            'total_units_on_hand' => $this->totalUnitsOnHand,
            'total_products_count' => $this->totalProductsCount,
            'valued_products_count' => $this->valuedProductsCount,
            'currency' => $this->currency,
        ];
    }
}
