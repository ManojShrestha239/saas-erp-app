<?php

declare(strict_types=1);

namespace Shared\Enums;

enum CsvDuplicateAction: string
{
    case Skip = 'skip';
    case Update = 'update';

    public function label(): string
    {
        return match ($this) {
            self::Skip => 'Skip Existing',
            self::Update => 'Update Existing',
        };
    }
}
