<?php

declare(strict_types=1);

namespace Modules\Payments\Enums;

enum PaymentStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Posted => 'Posted',
            self::Void => 'Voided',
        };
    }

    public function isImmutable(): bool
    {
        return $this !== self::Draft;
    }
}
