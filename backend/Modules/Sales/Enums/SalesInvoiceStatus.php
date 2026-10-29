<?php

declare(strict_types=1);

namespace Modules\Sales\Enums;

enum SalesInvoiceStatus: string
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
            self::Void => 'Void',
        };
    }

    public function isImmutable(): bool
    {
        return match ($this) {
            self::Draft => false,
            self::Posted, self::PartiallyPaid, self::Paid, self::Void => true,
        };
    }
}
