<?php

declare(strict_types=1);

namespace Modules\Accounting\Enums;

enum JournalEntryStatus: string
{
    case Posted = 'posted';
    case Reversed = 'reversed';

    public function label(): string
    {
        return match ($this) {
            self::Posted => 'Posted',
            self::Reversed => 'Reversed',
        };
    }

    public function isReversed(): bool
    {
        return $this === self::Reversed;
    }

    public function isImmutable(): bool
    {
        return true;
    }
}
