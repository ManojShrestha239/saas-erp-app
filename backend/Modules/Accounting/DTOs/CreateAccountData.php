<?php

declare(strict_types=1);

namespace Modules\Accounting\DTOs;

use Modules\Accounting\Enums\AccountType;

final readonly class CreateAccountData
{
    public function __construct(
        public string $code,
        public string $name,
        public AccountType $type,
        public ?string $parentId = null,
        public bool $isSystem = false,
        public ?string $systemName = null,
        public bool $isActive = true,
        public string $currency = 'USD',
        public ?string $description = null,
    ) {}
}
