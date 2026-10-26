<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

final readonly class UpdateCategoryData
{
    public function __construct(
        public ?string $name = null,
        public ?string $slug = null,
        public ?string $description = null,
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
        if ($this->slug !== null) {
            $data['slug'] = $this->slug;
        }
        if ($this->description !== null) {
            $data['description'] = $this->description;
        }
        if ($this->isActive !== null) {
            $data['is_active'] = $this->isActive;
        }

        return $data;
    }
}
