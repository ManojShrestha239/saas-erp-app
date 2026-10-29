<?php

declare(strict_types=1);

namespace Modules\Payments\Enums;

enum PaymentType: string
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';

    public function label(): string
    {
        return match ($this) {
            self::Inbound => 'Customer Receipt',
            self::Outbound => 'Supplier Payment',
        };
    }
}
