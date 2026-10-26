<?php

declare(strict_types=1);

namespace Modules\Inventory\Enums;

enum TaxCategory: string
{
    case Standard = 'standard';
    case ZeroRated = 'zero_rated';
    case Exempt = 'exempt';

    public function label(): string
    {
        return match ($this) {
            self::Standard => 'Standard Rate',
            self::ZeroRated => 'Zero Rated',
            self::Exempt => 'Exempt',
        };
    }
}
