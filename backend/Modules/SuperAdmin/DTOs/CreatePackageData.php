<?php

declare(strict_types=1);

namespace Modules\SuperAdmin\DTOs;

final readonly class CreatePackageData
{
    /**
     * @param  array<string, mixed>  $features
     */
    public function __construct(
        public string $name,
        public string $slug,
        public int $maxUsers,
        public int $maxWarehouses,
        public int $maxInvoicesPerMonth,
        public array $features,
        public int $priceCents,
    ) {}
}
