<?php

declare(strict_types=1);

namespace Modules\Accounting\DTOs;

use Modules\Accounting\Enums\AccountType;

final readonly class UpdateAccountData
{
    public function __construct(
        public ?string $code = null,
        public ?string $name = null,
        public ?AccountType $type = null,
        public ?string $parentId = null,
        public ?bool $isActive = null,
        public ?string $description = null,
    ) {}
}
