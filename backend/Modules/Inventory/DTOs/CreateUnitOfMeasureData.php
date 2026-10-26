<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

final readonly class CreateUnitOfMeasureData
{
    public function __construct(
        public string $name,
        public string $symbol,
        public bool $isActive = true,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'symbol' => $this->symbol,
            'is_active' => $this->isActive,
        ];
    }
}
