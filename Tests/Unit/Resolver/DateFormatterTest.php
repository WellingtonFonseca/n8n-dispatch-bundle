<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Resolver;

use MauticPlugin\N8nDispatchBundle\Resolver\DateFormatter;
use PHPUnit\Framework\TestCase;

class DateFormatterTest extends TestCase
{
    public function testFormatsADateValue(): void
    {
        $this->assertSame('21/09/2026', DateFormatter::format('2026-09-21', 'date'));
    }

    /**
     * Mautic's date column is a DATETIME: a date field is stored as 'Y-m-d 00:00:00'.
     */
    public function testFormatsADateValueStoredWithATime(): void
    {
        $this->assertSame('21/09/2026', DateFormatter::format('2026-09-21 00:00:00', 'date'));
        $this->assertSame('09/21/2026', DateFormatter::format('2026-09-21 00:00:00', 'date', 'en_US'));
    }

    public function testFormatsADatetimeValue(): void
    {
        $this->assertSame('21/09/2026 14:30:00', DateFormatter::format('2026-09-21 14:30:00', 'datetime'));
    }

    public function testLeavesNonDateTypesUnchanged(): void
    {
        $this->assertSame('Wellington', DateFormatter::format('Wellington', 'text'));
        $this->assertSame('42', DateFormatter::format('42', 'int'));
    }

    public function testLeavesAnEmptyValueUnchanged(): void
    {
        $this->assertSame('', DateFormatter::format('', 'date'));
    }

    public function testFallsBackToTheRawValueWhenItDoesNotMatchTheExpectedStorageFormat(): void
    {
        // Never actually seen live, but a safer default than throwing away
        // a value dispatch would otherwise have sent.
        $this->assertSame('not-a-date', DateFormatter::format('not-a-date', 'date'));
    }

    public function testEnglishUsesMonthFirst(): void
    {
        $this->assertSame('09/21/2026', DateFormatter::format('2026-09-21', 'date', 'en'));
        $this->assertSame('09/21/2026 14:30:00', DateFormatter::format('2026-09-21 14:30:00', 'datetime', 'en_US'));
    }

    public function testBritishEnglishKeepsDayFirst(): void
    {
        $this->assertSame('21/09/2026', DateFormatter::format('2026-09-21', 'date', 'en_GB'));
    }

    public function testUnknownLocaleFallsBackToPortuguese(): void
    {
        $this->assertSame('21/09/2026', DateFormatter::format('2026-09-21', 'date', 'xx_YY'));
        $this->assertSame('21/09/2026', DateFormatter::format('2026-09-21', 'date', ''));
    }

    public function testDefaultLocaleIsPortugueseWhenNoneIsGiven(): void
    {
        $this->assertSame('21/09/2026', DateFormatter::format('2026-09-21', 'date'));
    }
}
