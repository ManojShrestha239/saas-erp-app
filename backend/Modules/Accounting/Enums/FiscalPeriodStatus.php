<?php

declare(strict_types=1);

namespace Modules\Accounting\Enums;

enum FiscalPeriodStatus: string
{
    case Open = 'open';
    case Locked = 'locked';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Locked => 'Locked',
            self::Closed => 'Closed',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Open;
    }

    public function isLocked(): bool
    {
        return $this === self::Locked;
    }

    public function isClosed(): bool
    {
        return $this === self::Closed;
    }
}
