<?php

declare(strict_types=1);

namespace Modules\SuperAdmin\DTOs;

final readonly class UpdatePackageData
{
    /**
     * @param  array<string, mixed>|null  $features
     */
    public function __construct(
        public ?string $name = null,
        public ?string $slug = null,
        public ?int $maxUsers = null,
        public ?int $maxWarehouses = null,
        public ?int $maxInvoicesPerMonth = null,
        public ?array $features = null,
        public ?int $priceCents = null,
    ) {}
}
