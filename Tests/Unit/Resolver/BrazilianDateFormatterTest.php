<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Resolver;

use MauticPlugin\N8nDispatchBundle\Resolver\BrazilianDateFormatter;
use PHPUnit\Framework\TestCase;

class BrazilianDateFormatterTest extends TestCase
{
    public function testFormatsADateValue(): void
    {
        $this->assertSame('21/09/2026', BrazilianDateFormatter::format('2026-09-21', 'date'));
    }

    public function testFormatsADatetimeValue(): void
    {
        $this->assertSame('21/09/2026 14:30:00', BrazilianDateFormatter::format('2026-09-21 14:30:00', 'datetime'));
    }

    public function testLeavesNonDateTypesUnchanged(): void
    {
        $this->assertSame('Wellington', BrazilianDateFormatter::format('Wellington', 'text'));
        $this->assertSame('42', BrazilianDateFormatter::format('42', 'int'));
    }

    public function testLeavesAnEmptyValueUnchanged(): void
    {
        $this->assertSame('', BrazilianDateFormatter::format('', 'date'));
    }

    public function testFallsBackToTheRawValueWhenItDoesNotMatchTheExpectedStorageFormat(): void
    {
        // Never actually seen live, but a safer default than throwing away
        // a value dispatch would otherwise have sent.
        $this->assertSame('not-a-date', BrazilianDateFormatter::format('not-a-date', 'date'));
    }
}
