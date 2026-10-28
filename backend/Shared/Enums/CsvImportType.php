<?php

declare(strict_types=1);

namespace Shared\Enums;

enum CsvImportType: string
{
    case Products = 'products';
    case Contacts = 'contacts';

    public function label(): string
    {
        return match ($this) {
            self::Products => 'Products',
            self::Contacts => 'Contacts',
        };
    }
}
