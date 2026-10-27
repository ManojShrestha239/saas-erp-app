<?php

declare(strict_types=1);

namespace Modules\Contacts\Enums;

enum ContactType: string
{
    case Customer = 'customer';
    case Supplier = 'supplier';
    case Both = 'both';

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'Customer',
            self::Supplier => 'Supplier',
            self::Both => 'Customer & Supplier',
        };
    }

    public function isCustomer(): bool
    {
        return $this === self::Customer || $this === self::Both;
    }

    public function isSupplier(): bool
    {
        return $this === self::Supplier || $this === self::Both;
    }
}
