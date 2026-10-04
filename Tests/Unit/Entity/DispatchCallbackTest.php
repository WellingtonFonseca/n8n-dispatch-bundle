<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Entity;

use MauticPlugin\N8nDispatchBundle\Entity\DispatchCallback;
use PHPUnit\Framework\TestCase;

class DispatchCallbackTest extends TestCase
{
    private \DateTimeImmutable $at;

    protected function setUp(): void
    {
        $this->at = new \DateTimeImmutable('2026-10-02 10:00:00');
    }

    public function testACallbackEntryIsOfTheCallbackKindAndHasNoLogUntilLinked(): void
    {
        $callback = DispatchCallback::create(7, 'pending', null, [], $this->at);

        $this->assertSame(DispatchCallback::KIND_CALLBACK, $callback->getKind());
        $this->assertSame(7, $callback->getTrackingId());
        $this->assertNull($callback->getCampaignLogId());
        $this->assertNull($callback->getRefValue());
    }

    public function testWithLogLinksTheEntryToTheLogAndTheId(): void
    {
        $callback = DispatchCallback::create(7, 'pending', null, [], $this->at)->withLog(55, 'logSendHsmUuid', 'abc');

        $this->assertSame(55, $callback->getCampaignLogId());
        $this->assertSame('logSendHsmUuid', $callback->getRefType());
        $this->assertSame('abc', $callback->getRefValue());
    }

    public function testAnAcceptedDispatchEntryIsASuccessWithNoTracking(): void
    {
        $dispatch = DispatchCallback::createDispatch(55, true, 'logSendEmailId', '4001', null, $this->at);

        $this->assertSame(DispatchCallback::KIND_DISPATCH, $dispatch->getKind());
        $this->assertSame(DispatchCallback::DISPATCH_SUCCESS, $dispatch->getOutcome());
        $this->assertNull($dispatch->getTrackingId());
        $this->assertSame(55, $dispatch->getCampaignLogId());
        $this->assertEquals($this->at, $dispatch->getReceivedAt());
    }

    public function testARefusedDispatchEntryIsFailedAndKeepsTheMessage(): void
    {
        $dispatch = DispatchCallback::createDispatch(55, false, null, null, 'HTTP 500: boom', $this->at);

        $this->assertSame(DispatchCallback::DISPATCH_FAILED, $dispatch->getOutcome());
        $this->assertSame('HTTP 500: boom', $dispatch->getMessage());
        $this->assertNull($dispatch->getRefValue());
    }

    public function testASimulatedDispatchEntryKeepsTheModeAndHasNoId(): void
    {
        $test   = DispatchCallback::createSimulated(55, 'test', $this->at);
        $paused = DispatchCallback::createSimulated(55, 'paused', $this->at);

        $this->assertSame(DispatchCallback::KIND_DISPATCH, $test->getKind());
        $this->assertSame(DispatchCallback::DISPATCH_TEST, $test->getOutcome());
        $this->assertSame(DispatchCallback::DISPATCH_PAUSED, $paused->getOutcome());
        $this->assertNull($test->getRefValue());
        $this->assertNull($test->getTrackingId());
        $this->assertSame(55, $test->getCampaignLogId());
    }
}
