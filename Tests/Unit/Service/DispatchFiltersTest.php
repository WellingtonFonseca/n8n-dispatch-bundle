<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service;

use MauticPlugin\N8nDispatchBundle\Service\DispatchFilters;
use PHPUnit\Framework\TestCase;

class DispatchFiltersTest extends TestCase
{
    private \DateTimeZone $sp;

    protected function setUp(): void
    {
        $this->sp = new \DateTimeZone('America/Sao_Paulo');
    }

    public function testNothingAskedIsNoFilter(): void
    {
        $this->assertTrue(DispatchFilters::fromQuery([], $this->sp)->isEmpty());
    }

    public function testReadsEachFilter(): void
    {
        $f = DispatchFilters::fromQuery(['campaign' => '2', 'template' => 'hsm:7', 'status' => 'error', 'from' => '2026-10-02', 'to' => '2026-10-03'], $this->sp);

        $this->assertSame(2, $f->campaignId);
        $this->assertSame(['hsm', 7], $f->template);
        $this->assertSame('error', $f->status);
        $this->assertFalse($f->isEmpty());
    }

    public function testTheRangeIsWholeDaysInTheLocalZoneHeldAsUtc(): void
    {
        $f = DispatchFilters::fromQuery(['from' => '2026-10-02', 'to' => '2026-10-02'], $this->sp);

        // São Paulo is UTC-3: the local day 2 Oct runs from 03:00 UTC on the 2nd to 02:59:59 UTC on the 3rd.
        $this->assertSame('2026-10-02 03:00:00', $f->from?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-03 02:59:59', $f->to?->format('Y-m-d H:i:s'));
    }

    public function testMalformedValuesAreIgnored(): void
    {
        $f = DispatchFilters::fromQuery(['campaign' => 'abc', 'template' => 'push:1', 'status' => 'hacked', 'from' => '02/10/2026', 'to' => '2026-13-45'], $this->sp);

        $this->assertTrue($f->isEmpty());
    }

    public function testItGoesBackToTheQueryStringTheWayItWasTyped(): void
    {
        $query = ['campaign' => 2, 'template' => 'email:5', 'from' => '2026-10-02', 'to' => '2026-10-03', 'status' => 'failed'];

        $this->assertSame($query, DispatchFilters::fromQuery($query, $this->sp)->toQuery($this->sp));
    }

    public function testTheStatusIsTheCallbackShownOrFailed(): void
    {
        $outcomes = ['logSendHsmUuid|u1' => 'error', 'logSendHsmId|9' => 'success', 'logSendEmailId|4' => 'pending'];

        $this->assertSame('error', DispatchFilters::statusOf('hsm', ['logSendHsmId' => 9, 'logSendHsmUuid' => 'u1'], $outcomes));
        $this->assertSame('pending', DispatchFilters::statusOf('email', ['logSendEmailId' => 4], $outcomes));
        $this->assertSame('failed', DispatchFilters::statusOf('email', ['n8ndispatch' => ['httpStatusCode' => 400]], $outcomes));
        $this->assertNull(DispatchFilters::statusOf('sms', [], $outcomes));
        $this->assertNull(DispatchFilters::statusOf('email', ['logSendEmailId' => 77], $outcomes));
    }

    public function testDatesAreTypedInTheSystemLanguagesFormat(): void
    {
        $br = DispatchFilters::fromQuery(['from' => '02/10/2026', 'to' => '03/10/2026'], $this->sp, 'd/m/Y');
        $us = DispatchFilters::fromQuery(['from' => '10/02/2026'], $this->sp, 'm/d/Y');

        $this->assertSame('2026-10-02 03:00:00', $br->from?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-04 02:59:59', $br->to?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-02 03:00:00', $us->from?->format('Y-m-d H:i:s'));
    }

    public function testAnImpossibleDateIsIgnoredNotRolledOver(): void
    {
        $this->assertTrue(DispatchFilters::fromQuery(['from' => '31/02/2026'], $this->sp, 'd/m/Y')->isEmpty());
        $this->assertTrue(DispatchFilters::fromQuery(['from' => '2026-10-02'], $this->sp, 'd/m/Y')->isEmpty());
    }

    public function testItGoesBackToTheQueryStringInTheTypedFormat(): void
    {
        $query = ['from' => '02/10/2026', 'to' => '03/10/2026'];

        $this->assertSame($query, DispatchFilters::fromQuery($query, $this->sp, 'd/m/Y')->toQuery($this->sp, 'd/m/Y'));
    }
}
