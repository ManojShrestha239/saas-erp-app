<?php

declare(strict_types=1);

namespace Modules\Purchasing\Enums;

enum SupplierBillStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Posted => 'Posted',
            self::PartiallyPaid => 'Partially Paid',
            self::Paid => 'Paid',
            self::Void => 'Voided',
        };
    }

    public function canBePosted(): bool
    {
        return $this === self::Draft;
    }

    public function isImmutable(): bool
    {
        return $this !== self::Draft;
    }
}
