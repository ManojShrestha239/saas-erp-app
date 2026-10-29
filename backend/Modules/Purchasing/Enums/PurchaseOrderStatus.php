<?php

declare(strict_types=1);

namespace Modules\Purchasing\Enums;

enum PurchaseOrderStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case PartiallyReceived = 'partially_received';
    case Received = 'received';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Approved => 'Approved',
            self::PartiallyReceived => 'Partially Received',
            self::Received => 'Fully Received',
            self::Closed => 'Closed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function canBeApproved(): bool
    {
        return $this === self::Draft;
    }

    public function canReceive(): bool
    {
        return in_array($this, [self::Approved, self::PartiallyReceived], true);
    }

    public function canBeCancelled(): bool
    {
        return in_array($this, [self::Draft, self::Approved], true);
    }
}
