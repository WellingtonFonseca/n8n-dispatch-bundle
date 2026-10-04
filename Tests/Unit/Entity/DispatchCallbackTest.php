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

    public function testARefusedDispatchEntryIsFailedAndKeepsOnlyTheResponse(): void
    {
        $dispatch = DispatchCallback::createDispatch(55, false, null, null, ['error' => 'boom'], $this->at);

        $this->assertSame(DispatchCallback::DISPATCH_FAILED, $dispatch->getOutcome());
        $this->assertSame(['error' => 'boom'], $dispatch->getResponse());
        $this->assertNull($dispatch->getResend());
        $this->assertNull($dispatch->getMessage());
        $this->assertNull($dispatch->getRefValue());
        $this->assertSame(['response' => ['error' => 'boom']], $dispatch->getBodyArray());
    }

    public function testADispatchEntryWithNoResponseStoresNoJsonAtAll(): void
    {
        $dispatch = DispatchCallback::createDispatch(55, true, 'logSendEmailId', '4001', null, $this->at);

        $this->assertSame([], $dispatch->getBodyArray());
        $this->assertNull($dispatch->getResponse());
    }

    public function testAResendEntryKeepsWhoAndWhenApartFromTheResponse(): void
    {
        $dispatch = DispatchCallback::createDispatch(55, true, 'logSendHsmId', '777', ['logSendHsmId' => 777], $this->at, ['por' => 'a@b.com', 'em' => '2026-10-03 23:19']);

        $this->assertSame(['por' => 'a@b.com', 'em' => '2026-10-03 23:19'], $dispatch->getResend());
        $this->assertSame(['logSendHsmId' => 777], $dispatch->getResponse());
        $this->assertSame(['reenvio' => ['por' => 'a@b.com', 'em' => '2026-10-03 23:19'], 'response' => ['logSendHsmId' => 777]], $dispatch->getBodyArray());
    }

    public function testAResponseThatIsNotJsonIsKeptAsText(): void
    {
        $dispatch = DispatchCallback::createDispatch(55, false, null, null, 'Connection refused', $this->at);

        $this->assertSame('Connection refused', $dispatch->getResponse());
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

    public function testTheMessageIsKeptAsAJsonObjectWhateverShapeN8nSent(): void
    {
        $text   = DispatchCallback::create(7, 'error', 'caixa cheia', [], $this->at);
        $object = DispatchCallback::create(7, 'error', ['code' => 400, 'detail' => 'x'], [], $this->at);
        $list   = DispatchCallback::create(7, 'error', ['a', 'b'], [], $this->at);
        $none   = DispatchCallback::create(7, 'pending', null, [], $this->at);

        $this->assertSame('caixa cheia', $text->getMessage());
        $this->assertSame(['code' => 400, 'detail' => 'x'], $object->getMessage());
        $this->assertSame(['a', 'b'], $list->getMessage());
        $this->assertNull($none->getMessage());
        $this->assertSame(['message' => 'caixa cheia'], $text->getStoredMessage(), 'the column holds {"message": ...}');
        $this->assertNull($none->getStoredMessage());
    }

    public function testTheMessageAsTextIsTheTextOrTheJsonWrittenOut(): void
    {
        $this->assertSame('caixa cheia', DispatchCallback::create(7, 'error', 'caixa cheia', [], $this->at)->getMessageText());
        $this->assertSame("{\n    \"code\": 400\n}", DispatchCallback::create(7, 'error', ['code' => 400], [], $this->at)->getMessageText());
        $this->assertNull(DispatchCallback::create(7, 'pending', null, [], $this->at)->getMessageText());
    }
}
