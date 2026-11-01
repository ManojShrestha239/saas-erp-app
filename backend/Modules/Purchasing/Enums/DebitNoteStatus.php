<?php

declare(strict_types=1);

namespace Modules\Purchasing\Enums;

enum DebitNoteStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Posted => 'Posted',
            self::Void => 'Void',
        };
    }

    public function isImmutable(): bool
    {
        return match ($this) {
            self::Draft => false,
            self::Posted, self::Void => true,
        };
    }
}
