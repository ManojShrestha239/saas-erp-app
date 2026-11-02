<?php

declare(strict_types=1);

namespace Modules\Accounting\Enums;

enum VatReturnStatus: string
{
    case Draft = 'draft';
    case Filed = 'filed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Filed => 'Filed',
        };
    }
}
