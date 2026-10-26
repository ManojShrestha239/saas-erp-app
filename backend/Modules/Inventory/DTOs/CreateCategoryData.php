<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

final readonly class CreateCategoryData
{
    public function __construct(
        public string $name,
        public string $slug,
        public ?string $description = null,
        public bool $isActive = true,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'is_active' => $this->isActive,
        ];
    }
}
