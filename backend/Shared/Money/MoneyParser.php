<?php

declare(strict_types=1);

namespace Shared\Money;

use InvalidArgumentException;

final class MoneyParser
{
    /**
     * Parse a string representation of money (e.g. "12.50", "$1,250.00", "50") to integer cents without floating-point math.
     *
     * @throws InvalidArgumentException
     */
    public static function toCents(string|int|null $value, int $default = 0): int
    {
        if ($value === null) {
            return $default;
        }

        if (is_int($value)) {
            return $value * 100;
        }

        $cleaned = trim($value);

        if ($cleaned === '') {
            return $default;
        }

        // Remove currency symbols, commas, and spaces
        $cleaned = str_replace(['$', '€', '£', '¥', '₹', ',', ' '], '', $cleaned);

        $isNegative = str_starts_with($cleaned, '-');
        if ($isNegative) {
            $cleaned = substr($cleaned, 1);
        }

        if (! preg_match('/^\d+(\.\d{1,2})?$/', $cleaned)) {
            throw new InvalidArgumentException("Invalid money format: [{$value}]. Expected format like 10 or 10.50.");
        }

        $parts = explode('.', $cleaned);
        $whole = (int) $parts[0];
        $cents = 0;

        if (isset($parts[1])) {
            $decimalPart = $parts[1];
            if (strlen($decimalPart) === 1) {
                $decimalPart .= '0';
            }
            $cents = (int) substr($decimalPart, 0, 2);
        }

        $totalCents = ($whole * 100) + $cents;

        return $isNegative ? -$totalCents : $totalCents;
    }
}
