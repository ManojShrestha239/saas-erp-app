<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

final readonly class UpdateWarehouseData
{
    public function __construct(
        public ?string $name = null,
        public ?string $code = null,
        public ?string $address = null,
        public ?bool $isDefault = null,
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
        if ($this->code !== null) {
            $data['code'] = $this->code;
        }
        if ($this->address !== null) {
            $data['address'] = $this->address;
        }
        if ($this->isDefault !== null) {
            $data['is_default'] = $this->isDefault;
        }
        if ($this->isActive !== null) {
            $data['is_active'] = $this->isActive;
        }

        return $data;
    }
}
