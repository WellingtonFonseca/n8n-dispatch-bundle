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

    public function testAddsDaysToADate(): void
    {
        $this->assertSame('31/10/2026', DateFormatter::format('2026-10-21', 'date', 'pt_BR', 10));
        $this->assertSame('31/10/2026', DateFormatter::format('2026-10-21 00:00:00', 'date', 'pt_BR', 10));
        $this->assertSame('10/31/2026', DateFormatter::format('2026-10-21', 'date', 'en_US', 10));
    }

    public function testAddingDaysCrossesMonthsYearsAndLeapDays(): void
    {
        $this->assertSame('10/11/2026', DateFormatter::format('2026-10-31', 'date', 'pt_BR', 10));
        $this->assertSame('09/01/2027', DateFormatter::format('2026-12-30', 'date', 'pt_BR', 10));
        $this->assertSame('29/02/2028', DateFormatter::format('2028-02-19', 'date', 'pt_BR', 10));
        $this->assertSame('01/03/2027', DateFormatter::format('2027-02-19', 'date', 'pt_BR', 10));
    }

    public function testANegativeNumberGoesBack(): void
    {
        $this->assertSame('11/10/2026', DateFormatter::format('2026-10-21', 'date', 'pt_BR', -10));
        $this->assertSame('25/12/2025', DateFormatter::format('2026-01-04', 'date', 'pt_BR', -10));
    }

    public function testZeroDaysChangesNothing(): void
    {
        $this->assertSame('21/10/2026', DateFormatter::format('2026-10-21', 'date', 'pt_BR', 0));
    }

    public function testAddingDaysToADatetimeKeepsTheTimeOfDay(): void
    {
        $this->assertSame('31/10/2026 14:30:00', DateFormatter::format('2026-10-21 14:30:00', 'datetime', 'pt_BR', 10));
        $this->assertSame('01/11/2026 23:59:59', DateFormatter::format('2026-10-22 23:59:59', 'datetime', 'pt_BR', 10));
    }

    public function testTheTimeOfDayDoesNotShiftAcrossADaylightSavingChangeOfTheServerZone(): void
    {
        $zone = date_default_timezone_get();
        date_default_timezone_set('America/New_York');

        try {
            // 2026-03-08 is the day New York moves its clocks forward
            $this->assertSame('15/03/2026 02:30:00', DateFormatter::format('2026-03-05 02:30:00', 'datetime', 'pt_BR', 10));
            $this->assertSame('08/03/2026 02:30:00', DateFormatter::format('2026-03-07 02:30:00', 'datetime', 'pt_BR', 1));
        } finally {
            date_default_timezone_set($zone);
        }
    }

    public function testDaysAreNotAddedToAValueThatIsNotADate(): void
    {
        $this->assertSame('Wellington', DateFormatter::format('Wellington', 'text', 'pt_BR', 10));
        $this->assertSame('42', DateFormatter::format('42', 'int', 'pt_BR', 10));
        $this->assertSame('not-a-date', DateFormatter::format('not-a-date', 'date', 'pt_BR', 10));
        $this->assertSame('', DateFormatter::format('', 'date', 'pt_BR', 10));
    }

    public function testTheDaysAreCutAtTenYearsEitherWay(): void
    {
        $this->assertSame('18/10/2036', DateFormatter::format('2026-10-21', 'date', 'pt_BR', 3650));
        $this->assertSame(DateFormatter::format('2026-10-21', 'date', 'pt_BR', 3650), DateFormatter::format('2026-10-21', 'date', 'pt_BR', 3651));
        $this->assertSame(DateFormatter::format('2026-10-21', 'date', 'pt_BR', 3650), DateFormatter::format('2026-10-21', 'date', 'pt_BR', 9999999));
        $this->assertSame(DateFormatter::format('2026-10-21', 'date', 'pt_BR', -3650), DateFormatter::format('2026-10-21', 'date', 'pt_BR', -3651));
        $this->assertNotSame(DateFormatter::format('2026-10-21', 'date', 'pt_BR', 3649), DateFormatter::format('2026-10-21', 'date', 'pt_BR', 3650));
    }
}
