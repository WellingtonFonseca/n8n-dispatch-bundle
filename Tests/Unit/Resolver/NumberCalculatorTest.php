<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Resolver;

use MauticPlugin\N8nDispatchBundle\Resolver\NumberCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NumberCalculatorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function sums(): iterable
    {
        yield 'int plus' => ['15', 'int', '10', '25'];
        yield 'int minus' => ['15', 'int', '-10', '5'];
        yield 'int goes below zero' => ['5', 'int', '-15', '-10'];
        yield 'negative stays negative' => ['-5', 'int', '-10', '-15'];
        yield 'negative comes up to positive' => ['-5', 'int', '12', '7'];
        yield 'negative reaches zero' => ['-5', 'int', '5', '0'];
        yield 'zero minus is negative' => ['0', 'int', '-3', '-3'];
        yield 'same length, the days are bigger' => ['15', 'int', '-20', '-5'];
        yield 'same length, the value is bigger' => ['20', 'int', '-15', '5'];
        yield 'two nines carry two' => ['9', 'int', '9', '18'];
        yield 'carry over every digit' => ['999', 'int', '1', '1000'];
        yield 'borrow over every digit' => ['1000', 'int', '-1', '999'];
        yield 'number keeps its places' => ['12.5', 'number', '-10', '2.5'];
        yield 'number crosses zero' => ['0.50', 'number', '-1', '-0.50'];
        yield 'decimal keeps six places' => ['1.100000', 'decimal', '10', '11.100000'];
        yield 'decimal zero fraction result' => ['0.500000', 'decimal', '-1', '-0.500000'];
        yield 'no limit' => ['15', 'int', '5000', '5015'];
        yield 'bigger than a 64 bit int' => ['99999999999999999999.000000', 'decimal', '1', '100000000000000000000.000000'];
        yield 'zero days change nothing' => ['15', 'int', '0', '15'];
        yield 'zero with places changes nothing' => ['15', 'int', '0.0', '15'];
        yield 'int uses the whole part' => ['15', 'int', '13.9', '28'];
        yield 'int ignores a fraction alone' => ['15', 'int', '0.5', '15'];
        yield 'number uses the whole part and keeps its places' => ['12.5', 'number', '-10.7', '2.5'];
        yield 'decimal uses the places' => ['1.100000', 'decimal', '13.2', '14.300000'];
        yield 'decimal subtracts a fraction' => ['1.100000', 'decimal', '-0.5', '0.600000'];
        yield 'decimal crosses zero' => ['0.100000', 'decimal', '-0.5', '-0.400000'];
        yield 'decimal with more places than the value' => ['1.5', 'decimal', '0.25', '1.75'];
        yield 'a plus sign' => ['15', 'int', '+10', '25'];
        yield 'amount is not a number' => ['15', 'int', 'dez', '15'];
        yield 'text before the amount' => ['15', 'int', 'x10', '15'];
        yield 'text after the amount' => ['15', 'int', '10x', '15'];
        yield 'zero leaves a value as it is written' => ['007', 'int', '0', '007'];
        yield 'zero with places leaves a value as it is written' => ['007', 'int', '0.0', '007'];
        yield 'amount is empty' => ['15', 'int', '', '15'];
        yield 'not a number type' => ['15', 'text', '10', '15'];
        yield 'date type is not touched' => ['2026-10-21', 'date', '10', '2026-10-21'];
        yield 'empty value' => ['', 'int', '10', ''];
        yield 'text value' => ['abc', 'int', '10', 'abc'];
        yield 'scientific value' => ['1e3', 'number', '10', '1e3'];
        yield 'text around a number' => ['x15', 'int', '10', 'x15'];
        yield 'number then text' => ['15x', 'int', '10', '15x'];
        yield 'fraction without digits' => ['15.', 'number', '10', '15.'];
    }

    #[DataProvider('sums')]
    public function testAdd(string $value, string $type, string $amount, string $expected): void
    {
        $this->assertSame($expected, NumberCalculator::add($value, $type, $amount));
    }
}
