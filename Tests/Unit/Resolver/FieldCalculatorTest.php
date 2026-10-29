<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Resolver;

use MauticPlugin\N8nDispatchBundle\Resolver\FieldCalculator;
use PHPUnit\Framework\TestCase;

class FieldCalculatorTest extends TestCase
{
    public function testADateMovesByTheWholePartOfTheCalculation(): void
    {
        $this->assertSame('24/10/2026', FieldCalculator::apply('2026-10-21', 'date', 'pt_BR', '3.9'));
        $this->assertSame('18/10/2026', FieldCalculator::apply('2026-10-21', 'date', 'pt_BR', '-3'));
    }

    public function testAnIntTakesTheWholePartAndADecimalTheWholeCalculation(): void
    {
        $this->assertSame('18', FieldCalculator::apply('15', 'int', 'pt_BR', '3.9'));
        $this->assertSame('14.300000', FieldCalculator::apply('1.100000', 'decimal', 'pt_BR', '13.2'));
    }

    public function testAnotherTypeAndNoCalculationAreLeftAsTheyAre(): void
    {
        $this->assertSame('15', FieldCalculator::apply('15', 'text', 'pt_BR', '10'));
        $this->assertSame('2026-10-21', FieldCalculator::apply('2026-10-21', 'text', 'pt_BR', '10'));
        $this->assertSame('15', FieldCalculator::apply('15', 'int', 'pt_BR', '0'));
    }
}
