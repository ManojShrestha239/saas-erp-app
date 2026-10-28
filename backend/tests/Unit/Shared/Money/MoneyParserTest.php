<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Money;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Shared\Money\MoneyParser;

final class MoneyParserTest extends TestCase
{
    public function test_parses_null_and_empty_string_to_zero(): void
    {
        $this->assertSame(0, MoneyParser::toCents(null));
        $this->assertSame(0, MoneyParser::toCents(''));
        $this->assertSame(0, MoneyParser::toCents('   '));
    }

    public function test_parses_whole_numbers(): void
    {
        $this->assertSame(10000, MoneyParser::toCents('100'));
        $this->assertSame(0, MoneyParser::toCents('0'));
    }

    public function test_parses_decimal_amounts(): void
    {
        $this->assertSame(1234, MoneyParser::toCents('12.34'));
        $this->assertSame(1230, MoneyParser::toCents('12.3'));
        $this->assertSame(5, MoneyParser::toCents('0.05'));
    }

    public function test_parses_amounts_with_commas_and_currency_symbols(): void
    {
        $this->assertSame(123456, MoneyParser::toCents('$1,234.56'));
        $this->assertSame(100000000, MoneyParser::toCents('1,000,000.00'));
    }

    public function test_parses_negative_amounts(): void
    {
        $this->assertSame(-1550, MoneyParser::toCents('-15.50'));
        $this->assertSame(-50000, MoneyParser::toCents('-$500.00'));
    }

    public function test_rejects_sub_cents_beyond_two_decimal_places(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MoneyParser::toCents('12.345');
    }

    public function test_throws_exception_on_invalid_numeric_input(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MoneyParser::toCents('not-a-number');
    }
}
