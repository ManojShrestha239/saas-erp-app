<?php

declare(strict_types=1);

namespace Modules\Accounting\Enums;

enum JournalEntryStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Posted = 'posted';
    case Rejected = 'rejected';
    case Reversed = 'reversed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingApproval => 'Pending Approval',
            self::Posted => 'Posted',
            self::Rejected => 'Rejected',
            self::Reversed => 'Reversed',
        };
    }

    public function isDraft(): bool
    {
        return $this === self::Draft;
    }

    public function isPendingApproval(): bool
    {
        return $this === self::PendingApproval;
    }

    public function isPosted(): bool
    {
        return $this === self::Posted;
    }

    public function isRejected(): bool
    {
        return $this === self::Rejected;
    }

    public function isReversed(): bool
    {
        return $this === self::Reversed;
    }

    public function isImmutable(): bool
    {
        return match ($this) {
            self::Posted, self::Reversed => true,
            self::Draft, self::PendingApproval, self::Rejected => false,
        };
    }
}
