<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Resolver;

use MauticPlugin\N8nDispatchBundle\Resolver\DecimalFormatter;
use PHPUnit\Framework\TestCase;

class DecimalFormatterTest extends TestCase
{
    /**
     * @dataProvider decimalValues
     */
    public function testFormatsDecimalValues(string $stored, string $expected): void
    {
        $this->assertSame($expected, DecimalFormatter::format($stored, 'decimal'));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function decimalValues(): array
    {
        return [
            'two places by default'                      => ['1.100000', '1.10'],
            'two real places'                            => ['3.750000', '3.75'],
            'whole number keeps two places'              => ['2.000000', '2.00'],
            'third place shown when not zero'            => ['1.000100', '1.0001'],
            'fifth place shown when not zero'            => ['1.000010', '1.00001'],
            'all six places'                             => ['0.123456', '0.123456'],
            'zero in the middle is kept'                 => ['1.050000', '1.05'],
            'zero'                                       => ['0.000000', '0.00'],
            'negative'                                   => ['-0.500000', '-0.50'],
            'negative with extra places'                 => ['-2.001000', '-2.001'],
            'no fractional part at all'                  => ['7', '7.00'],
            'fewer places than the column'               => ['1.5', '1.50'],
            'large integer part is not turned to float'  => ['12345678901234.100000', '12345678901234.10'],
        ];
    }

    public function testLeavesNonDecimalTypesUnchanged(): void
    {
        $this->assertSame('1.100000', DecimalFormatter::format('1.100000', 'int'));
        $this->assertSame('Wellington', DecimalFormatter::format('Wellington', 'text'));
    }

    public function testLeavesAnEmptyOrNonNumericValueUnchanged(): void
    {
        $this->assertSame('', DecimalFormatter::format('', 'decimal'));
        $this->assertSame('abc', DecimalFormatter::format('abc', 'decimal'));
        $this->assertSame('1e-06', DecimalFormatter::format('1e-06', 'decimal'));
    }
}
