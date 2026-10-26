<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

final readonly class UpdateUnitOfMeasureData
{
    public function __construct(
        public ?string $name = null,
        public ?string $symbol = null,
        public ?bool $isActive = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toFilteredArray(): array
    {
        $data = [];

        if ($this->name !== null) {
            $data['name'] = $this->name;
        }
        if ($this->symbol !== null) {
            $data['symbol'] = $this->symbol;
        }
        if ($this->isActive !== null) {
            $data['is_active'] = $this->isActive;
        }

        return $data;
    }
}
