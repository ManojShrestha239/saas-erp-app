<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

final readonly class CreateWarehouseData
{
    public function __construct(
        public string $name,
        public string $code,
        public ?string $address = null,
        public bool $isDefault = false,
        public bool $isActive = true,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'code' => $this->code,
            'address' => $this->address,
            'is_default' => $this->isDefault,
            'is_active' => $this->isActive,
        ];
    }
}
