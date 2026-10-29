<?php

declare(strict_types=1);

namespace Modules\Payments\Enums;

enum PayableType: string
{
    case Invoice = 'invoice';
    case Bill = 'bill';

    public function label(): string
    {
        return match ($this) {
            self::Invoice => 'Sales Invoice',
            self::Bill => 'Supplier Bill',
        };
    }
}
