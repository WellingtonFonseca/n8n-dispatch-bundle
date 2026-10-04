<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service;

use MauticPlugin\N8nDispatchBundle\Entity\DispatchCallback;
use MauticPlugin\N8nDispatchBundle\Entity\DispatchCallbackRepository;
use MauticPlugin\N8nDispatchBundle\Entity\DispatchTracking;
use MauticPlugin\N8nDispatchBundle\Entity\DispatchTrackingRepository;
use MauticPlugin\N8nDispatchBundle\Service\StatusTracker;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class StatusTrackerTest extends TestCase
{
    /** @var DispatchTrackingRepository&MockObject */
    private DispatchTrackingRepository $trackings;

    /** @var DispatchCallbackRepository&MockObject */
    private DispatchCallbackRepository $callbacks;

    private StatusTracker $tracker;

    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->trackings = $this->createMock(DispatchTrackingRepository::class);
        $this->callbacks = $this->createMock(DispatchCallbackRepository::class);
        $this->tracker   = new StatusTracker($this->trackings, $this->callbacks);
        $this->now       = new \DateTimeImmutable('2026-10-02 10:00:00');
    }

    private function existing(string $refType, string $refValue, int $id = 7): DispatchTracking
    {
        $tracking = DispatchTracking::create(DispatchTracking::CHANNEL_EMAIL, $refType, $refValue, 'g', new \DateTimeImmutable('2026-10-02 09:00:00'));
        (new \ReflectionProperty($tracking, 'id'))->setValue($tracking, $id);

        return $tracking;
    }

    public function testRegisterCreatesOnePendingRowPerRefSharingTheGroupKey(): void
    {
        $saved = [];
        $this->trackings->method('findOneByRef')->willReturn(null);
        $this->trackings->method('saveEntity')->willReturnCallback(function (DispatchTracking $t) use (&$saved): void {
            $saved[] = $t;
        });

        $this->tracker->register(DispatchTracking::CHANNEL_HSM, [
            DispatchTracking::REF_HSM_ID   => 4298591,
            DispatchTracking::REF_HSM_UUID => '5c48a721-8cb1-43c7-ac46-048ce65b4233',
        ], $this->now);

        $this->assertCount(2, $saved);
        $this->assertSame('logSendHsmId', $saved[0]->getRefType());
        $this->assertSame('4298591', $saved[0]->getRefValue());
        $this->assertSame('logSendHsmUuid', $saved[1]->getRefType());
        $this->assertSame(DispatchTracking::OUTCOME_PENDING, $saved[0]->getOutcome());
        $this->assertSame(DispatchTracking::CHANNEL_HSM, $saved[1]->getChannel());
        $this->assertNotSame('', $saved[0]->getGroupKey());
        $this->assertSame($saved[0]->getGroupKey(), $saved[1]->getGroupKey());
    }

    public function testRegisterStartsTheHistoryWithAPendingEntryAtTheDispatchDate(): void
    {
        $history = [];
        $this->trackings->method('findOneByRef')->willReturn(null);
        $this->callbacks->method('saveEntity')->willReturnCallback(function (DispatchCallback $c) use (&$history): void {
            $history[] = $c;
        });
        $earlier = new \DateTimeImmutable('2026-09-29 14:00:00');

        $this->tracker->register(DispatchTracking::CHANNEL_EMAIL, [DispatchTracking::REF_EMAIL => 4001], $earlier);

        $this->assertCount(1, $history);
        $this->assertSame(DispatchTracking::OUTCOME_PENDING, $history[0]->getOutcome());
        $this->assertNull($history[0]->getMessage());
        $this->assertEquals($earlier, $history[0]->getReceivedAt());
    }

    public function testForgetDeletesTheRowsButKeepsTheirHistory(): void
    {
        $row = $this->existing(DispatchTracking::REF_EMAIL, '4001', 7);
        $old = DispatchCallback::create(7, 'pending', null, [], $this->now)->withLog(55, 'logSendEmailId', '4001');
        $this->trackings->method('findByRefs')->with(['logSendEmailId' => '4001'])->willReturn([$row]);
        $this->callbacks->method('findByTrackingIds')->with([7])->willReturn([$old]);
        $this->callbacks->expects($this->never())->method('deleteEntities');
        $this->trackings->expects($this->once())->method('deleteEntities')->with([$row]);

        $this->tracker->forget(['logSendEmailId' => 4001, 'other' => 'x'], 55);

        $this->assertSame(55, $old->getCampaignLogId());
    }

    public function testForgetLinksHistoryFromBeforeThisFeatureToTheLog(): void
    {
        $row    = $this->existing(DispatchTracking::REF_EMAIL, '4001', 7);
        $legacy = DispatchCallback::create(7, 'pending', null, [], $this->now);
        $this->trackings->method('findByRefs')->willReturn([$row]);
        $this->callbacks->method('findByTrackingIds')->willReturn([$legacy]);
        $this->callbacks->expects($this->once())->method('saveEntity')->with($legacy);

        $this->tracker->forget(['logSendEmailId' => 4001], 55);

        $this->assertSame(55, $legacy->getCampaignLogId());
        $this->assertSame('logSendEmailId', $legacy->getRefType());
        $this->assertSame('4001', $legacy->getRefValue());
    }

    public function testForgetWithNoIdsTouchesNothing(): void
    {
        $this->trackings->method('findByRefs')->willReturn([]);
        $this->callbacks->expects($this->never())->method('deleteEntities');
        $this->trackings->expects($this->never())->method('deleteEntities');

        $this->tracker->forget([]);
    }

    public function testAnnotateWritesTheNoteOnTheRowAndOnItsFirstHistoryEntry(): void
    {
        $row   = $this->existing(DispatchTracking::REF_EMAIL, '4001', 7);
        $first = DispatchCallback::create(7, 'pending', null, [], $this->now);
        $this->trackings->method('findByRefs')->willReturn([$row]);
        $this->callbacks->method('findByTrackingIds')->willReturn([$first]);

        $this->tracker->annotate(['logSendEmailId' => 4001], "Reenvio feito em 02/10/2026 16:30\nPor a@b.com");

        $this->assertSame("Reenvio feito em 02/10/2026 16:30\nPor a@b.com", $row->getMessage());
        $this->assertSame("Reenvio feito em 02/10/2026 16:30\nPor a@b.com", $first->getMessage());
    }

    public function testRegisterSkipsAnEmptyRef(): void
    {
        $this->trackings->method('findOneByRef')->willReturn(null);
        $this->trackings->expects($this->once())->method('saveEntity');

        $this->tracker->register(DispatchTracking::CHANNEL_HSM, [
            DispatchTracking::REF_HSM_ID   => 4298591,
            DispatchTracking::REF_HSM_UUID => null,
        ], $this->now);
    }

    public function testRegisterDoesNotDuplicateARefThatIsAlreadyTracked(): void
    {
        $this->trackings->method('findOneByRef')->willReturn($this->existing(DispatchTracking::REF_EMAIL, '4298591'));
        $this->trackings->expects($this->never())->method('saveEntity');

        $this->tracker->register(DispatchTracking::CHANNEL_EMAIL, [DispatchTracking::REF_EMAIL => 4298591], $this->now);
    }

    public function testRegisterReturnsHowManyRowsItCreated(): void
    {
        $this->trackings->method('findOneByRef')->willReturnCallback(
            fn (string $type, string $value) => 'logSendHsmId' === $type ? $this->existing($type, $value) : null
        );

        $created = $this->tracker->register(DispatchTracking::CHANNEL_HSM, [
            DispatchTracking::REF_HSM_ID   => 4298591,
            DispatchTracking::REF_HSM_UUID => 'abc',
        ], $this->now);

        $this->assertSame(1, $created);
    }

    public function testRegisterUsesTheGivenDateAsTheDispatchDate(): void
    {
        $saved = [];
        $this->trackings->method('findOneByRef')->willReturn(null);
        $this->trackings->method('saveEntity')->willReturnCallback(function (DispatchTracking $t) use (&$saved): void {
            $saved[] = $t;
        });
        $earlier = new \DateTimeImmutable('2026-09-29 14:00:00');

        $this->tracker->register(DispatchTracking::CHANNEL_EMAIL, [DispatchTracking::REF_EMAIL => 4001], $earlier);

        $this->assertEquals($earlier, $saved[0]->getDispatchedAt());
    }

    public function testIsTrackedAsksTheRepository(): void
    {
        $this->trackings->method('findOneByRef')->willReturnCallback(
            fn (string $type, string $value) => '1' === $value ? $this->existing($type, $value) : null
        );

        $this->assertTrue($this->tracker->isTracked('logSendEmailId', '1'));
        $this->assertFalse($this->tracker->isTracked('logSendEmailId', '2'));
    }

    public function testApplyCountsAnUnknownRefAndSavesNothing(): void
    {
        $this->trackings->method('findOneByRef')->willReturn(null);
        $this->trackings->expects($this->never())->method('saveEntity');
        $this->callbacks->expects($this->never())->method('saveEntity');

        $summary = $this->tracker->apply([
            ['refType' => 'logSendEmailId', 'refValue' => '999', 'outcome' => 'success', 'message' => null, 'body' => []],
        ], $this->now);

        $this->assertSame(['changed' => 0, 'unchanged' => 0, 'unknown' => 1], $summary);
    }

    public function testApplySameOutcomeOnlyUpdatesTheRowAndWritesNoHistory(): void
    {
        $row = $this->existing(DispatchTracking::REF_EMAIL, '4298591');
        $this->trackings->method('findOneByRef')->with('logSendEmailId', '4298591')->willReturn($row);
        $this->trackings->expects($this->once())->method('saveEntity')->with($row);
        $this->callbacks->expects($this->never())->method('saveEntity');

        $summary = $this->tracker->apply([
            ['refType' => 'logSendEmailId', 'refValue' => '4298591', 'outcome' => 'pending', 'message' => null, 'body' => []],
        ], $this->now);

        $this->assertSame(['changed' => 0, 'unchanged' => 1, 'unknown' => 0], $summary);
        $this->assertSame(1, $row->getCheckCount());
    }

    public function testApplyAChangeWritesOneHistoryRowAndUpdatesTheRow(): void
    {
        $row = $this->existing(DispatchTracking::REF_EMAIL, '4298591', 7);
        $this->trackings->method('findOneByRef')->willReturn($row);
        $this->trackings->expects($this->once())->method('saveEntity')->with($row);

        $history = [];
        $this->callbacks->method('saveEntity')->willReturnCallback(function (DispatchCallback $c) use (&$history): void {
            $history[] = $c;
        });

        $body    = ['logSendEmailId' => 4298591, 'outcome' => 'error', 'message' => 'caixa cheia'];
        $summary = $this->tracker->apply([
            ['refType' => 'logSendEmailId', 'refValue' => '4298591', 'outcome' => 'error', 'message' => 'caixa cheia', 'body' => $body],
        ], $this->now);

        $this->assertSame(['changed' => 1, 'unchanged' => 0, 'unknown' => 0], $summary);
        $this->assertSame(DispatchTracking::OUTCOME_ERROR, $row->getOutcome());
        $this->assertCount(1, $history);
        $this->assertSame(7, $history[0]->getTrackingId());
        $this->assertSame('error', $history[0]->getOutcome());
        $this->assertSame('caixa cheia', $history[0]->getMessage());
        $this->assertSame($body, $history[0]->getBodyArray());
        $this->assertEquals($this->now, $history[0]->getReceivedAt());
    }

    public function testPendingAsksTheRepositoryWithTheAgeLimitAsADate(): void
    {
        $this->trackings->expects($this->once())
            ->method('findPending')
            ->with('email', 'pending', $this->equalTo(new \DateTimeImmutable('2026-09-25 10:00:00')), 100, 0)
            ->willReturn([]);

        $this->tracker->pending(DispatchTracking::CHANNEL_EMAIL, DispatchTracking::OUTCOME_PENDING, 100, 7, $this->now);
    }

    public function testPendingContinuesAfterTheGivenId(): void
    {
        $this->trackings->expects($this->once())
            ->method('findPending')
            ->with('sms', 'pending', $this->anything(), 100, 250)
            ->willReturn([]);

        $this->tracker->pending(DispatchTracking::CHANNEL_SMS, DispatchTracking::OUTCOME_PENDING, 100, 7, $this->now, 250);
    }

    public function testViewForMetadataListsEachTrackedRefWithItsHistory(): void
    {
        $mirror = $this->existing(DispatchTracking::REF_HSM_ID, '4298591', 1);
        $meta   = $this->existing(DispatchTracking::REF_HSM_UUID, 'abc', 2);
        $meta->applyOutcome(DispatchTracking::OUTCOME_SUCCESS, null, $this->now);

        $this->trackings->expects($this->once())->method('findByRefs')
            ->with([
                'logSendHsmId'   => '4298591',
                'logSendHsmUuid' => 'abc',
            ])
            ->willReturn([$mirror, $meta]);

        $entry = new DispatchCallback();
        $entry->setTrackingId(2);
        $entry->setOutcome('success');
        $entry->setReceivedAt($this->now);
        $this->callbacks->method('findByTrackingIds')->with([1, 2])->willReturn([$entry]);

        $view = $this->tracker->viewForMetadata(['logSendHsmId' => 4298591, 'logSendHsmUuid' => 'abc', 'n8ndispatch' => []]);

        $this->assertCount(2, $view);
        $this->assertSame('logSendHsmId', $view[0]['refType']);
        $this->assertSame('pending', $view[0]['outcome']);
        $this->assertSame([], $view[0]['history']);
        $this->assertSame('logSendHsmUuid', $view[1]['refType']);
        $this->assertSame('success', $view[1]['outcome']);
        $this->assertCount(1, $view[1]['history']);
        $this->assertSame('success', $view[1]['history'][0]['outcome']);
    }

    public function testViewForMetadataWithoutAnyRefDoesNotTouchTheDatabase(): void
    {
        $this->trackings->expects($this->never())->method('findByRefs');

        $this->assertSame([], $this->tracker->viewForMetadata(['n8ndispatch' => ['status' => 'test']]));
    }

    public function testRegisterGivesTheRowAndItsFirstEntryTheCampaignLogId(): void
    {
        $tracking = null;
        $first    = null;
        $this->trackings->method('findOneByRef')->willReturn(null);
        $this->trackings->method('saveEntity')->willReturnCallback(function (DispatchTracking $t) use (&$tracking): void {
            $tracking = $t;
        });
        $this->callbacks->method('saveEntity')->willReturnCallback(function (DispatchCallback $c) use (&$first): void {
            $first = $c;
        });

        $this->tracker->register(DispatchTracking::CHANNEL_EMAIL, [DispatchTracking::REF_EMAIL => 4001], $this->now, 55);

        $this->assertSame(55, $tracking->getCampaignLogId());
        $this->assertSame(55, $first->getCampaignLogId());
        $this->assertSame(DispatchCallback::KIND_CALLBACK, $first->getKind());
        $this->assertSame('logSendEmailId', $first->getRefType());
        $this->assertSame('4001', $first->getRefValue());
    }

    public function testApplyCopiesTheLogIdAndTheIdToTheNewHistoryEntry(): void
    {
        $row = DispatchTracking::create(DispatchTracking::CHANNEL_EMAIL, DispatchTracking::REF_EMAIL, '4001', 'g', $this->now, 55);
        (new \ReflectionProperty($row, 'id'))->setValue($row, 7);
        $saved = null;
        $this->trackings->method('findOneByRef')->willReturn($row);
        $this->callbacks->method('saveEntity')->willReturnCallback(function (DispatchCallback $c) use (&$saved): void {
            $saved = $c;
        });

        $this->tracker->apply([
            ['refType' => 'logSendEmailId', 'refValue' => '4001', 'outcome' => 'error', 'message' => 'boom', 'body' => []],
        ], $this->now);

        $this->assertSame(55, $saved->getCampaignLogId());
        $this->assertSame('4001', $saved->getRefValue());
        $this->assertSame(7, $saved->getTrackingId());
    }

    public function testRecordDispatchWritesOneDispatchEntryWithTheFirstId(): void
    {
        $saved = [];
        $this->callbacks->method('saveEntity')->willReturnCallback(function (DispatchCallback $c) use (&$saved): void {
            $saved[] = $c;
        });

        $this->tracker->recordDispatch(55, true, [DispatchTracking::REF_HSM_ID => 4298591, DispatchTracking::REF_HSM_UUID => 'abc'], null, $this->now);

        $this->assertCount(1, $saved);
        $this->assertSame(DispatchCallback::KIND_DISPATCH, $saved[0]->getKind());
        $this->assertSame(DispatchCallback::DISPATCH_SUCCESS, $saved[0]->getOutcome());
        $this->assertSame(55, $saved[0]->getCampaignLogId());
        $this->assertSame('logSendHsmId', $saved[0]->getRefType());
        $this->assertSame('4298591', $saved[0]->getRefValue());
        $this->assertNull($saved[0]->getTrackingId());
        $this->assertEquals($this->now, $saved[0]->getReceivedAt());
    }

    public function testRecordDispatchOfARefusedCallKeepsTheResponseAndNoId(): void
    {
        $saved = null;
        $this->callbacks->method('saveEntity')->willReturnCallback(function (DispatchCallback $c) use (&$saved): void {
            $saved = $c;
        });

        $this->tracker->recordDispatch(55, false, [], ['error' => 'template not found'], $this->now);

        $this->assertSame(DispatchCallback::DISPATCH_FAILED, $saved->getOutcome());
        $this->assertSame(['error' => 'template not found'], $saved->getResponse());
        $this->assertNull($saved->getMessage());
        $this->assertNull($saved->getRefValue());
    }

    public function testRecordDispatchOfAResendKeepsWhoAndWhen(): void
    {
        $saved = null;
        $this->callbacks->method('saveEntity')->willReturnCallback(function (DispatchCallback $c) use (&$saved): void {
            $saved = $c;
        });

        $this->tracker->recordDispatch(55, true, [DispatchTracking::REF_EMAIL => 4001], ['ok' => true], $this->now, ['por' => 'a@b.com', 'em' => '2026-10-02 10:00']);

        $this->assertSame(['por' => 'a@b.com', 'em' => '2026-10-02 10:00'], $saved->getResend());
    }

    public function testRecordDispatchWithoutALogIdWritesNothing(): void
    {
        $this->callbacks->expects($this->never())->method('saveEntity');

        $this->tracker->recordDispatch(null, true, [DispatchTracking::REF_EMAIL => 4001], null, $this->now);
    }

    public function testHistoryForLogReturnsTheEntriesTheRepositoryFoundNewestFirst(): void
    {
        $dispatch = DispatchCallback::createDispatch(55, true, 'logSendEmailId', '4001', null, new \DateTimeImmutable('2026-10-02 09:00:00'));
        $callback = DispatchCallback::create(7, 'error', 'boom', [], new \DateTimeImmutable('2026-10-02 09:30:00'))->withLog(55, 'logSendEmailId', '4001');
        $this->callbacks->method('findByCampaignLogId')->with(55)->willReturn([$callback, $dispatch]);

        $history = $this->tracker->historyForLog(55);

        $this->assertSame(['callback', 'dispatch'], array_column($history, 'kind'));
        $this->assertSame(['error', 'success'], array_column($history, 'outcome'));
        $this->assertSame('boom', $history[0]['message']);
        $this->assertSame('4001', $history[1]['refValue']);
        $this->assertNull($history[1]['response']);
        $this->assertNull($history[1]['resend']);
    }

    public function testAdoptLinksATrackedDispatchAndCreatesItsMissingDispatchEntry(): void
    {
        $row    = $this->existing(DispatchTracking::REF_EMAIL, '4001', 7);
        $legacy = DispatchCallback::create(7, 'pending', null, [], $this->now);
        $at     = new \DateTimeImmutable('2026-09-29 14:00:00');
        $this->trackings->method('findByRefs')->willReturn([$row]);
        $this->callbacks->method('findByTrackingIds')->willReturn([$legacy]);
        $this->callbacks->method('findByCampaignLogId')->with(55)->willReturn([]);
        $saved = [];
        $this->callbacks->method('saveEntity')->willReturnCallback(function (DispatchCallback $c) use (&$saved): void {
            $saved[] = $c;
        });

        $this->tracker->adopt(55, [DispatchTracking::REF_EMAIL => '4001'], $at);

        $this->assertSame(55, $row->getCampaignLogId());
        $this->assertSame(55, $legacy->getCampaignLogId());
        $kinds = array_map(fn (DispatchCallback $c): string => $c->getKind(), $saved);
        $this->assertContains(DispatchCallback::KIND_DISPATCH, $kinds);
    }

    public function testAdoptIsIdempotentWhenTheDispatchEntryAlreadyExists(): void
    {
        $row = $this->existing(DispatchTracking::REF_EMAIL, '4001', 7);
        $row->setCampaignLogId(55);
        $linked   = DispatchCallback::create(7, 'pending', null, [], $this->now)->withLog(55, 'logSendEmailId', '4001');
        $dispatch = DispatchCallback::createDispatch(55, true, 'logSendEmailId', '4001', null, $this->now);
        $this->trackings->method('findByRefs')->willReturn([$row]);
        $this->callbacks->method('findByTrackingIds')->willReturn([$linked]);
        $this->callbacks->method('findByCampaignLogId')->willReturn([$linked, $dispatch]);
        $this->callbacks->expects($this->never())->method('saveEntity');
        $this->trackings->expects($this->never())->method('saveEntity');

        $this->tracker->adopt(55, [DispatchTracking::REF_EMAIL => '4001'], $this->now);
    }

    public function testRecordSimulatedWritesATestOrPausedDispatchEntry(): void
    {
        $saved = [];
        $this->callbacks->method('saveEntity')->willReturnCallback(function (DispatchCallback $c) use (&$saved): void {
            $saved[] = $c;
        });

        $this->tracker->recordSimulated(55, 'test', $this->now);
        $this->tracker->recordSimulated(56, 'paused', $this->now);

        $this->assertSame([DispatchCallback::DISPATCH_TEST, DispatchCallback::DISPATCH_PAUSED], array_map(fn (DispatchCallback $c): string => $c->getOutcome(), $saved));
        $this->assertSame([55, 56], array_map(fn (DispatchCallback $c): ?int => $c->getCampaignLogId(), $saved));
    }

    public function testRecordSimulatedIgnoresAnyOtherModeAndAMissingLogId(): void
    {
        $this->callbacks->expects($this->never())->method('saveEntity');

        $this->tracker->recordSimulated(55, 'production', $this->now);
        $this->tracker->recordSimulated(null, 'test', $this->now);
    }

    public function testResetDeletesEveryHistoryEntryAndEveryTrackingRow(): void
    {
        $this->callbacks->expects($this->once())->method('deleteAll');
        $this->trackings->expects($this->once())->method('deleteAll');

        $this->tracker->reset();
    }

    public function testAdoptKeepsTheResponseOnTheDispatchEntryItCreates(): void
    {
        $this->trackings->method('findByRefs')->willReturn([]);
        $this->callbacks->method('findByCampaignLogId')->willReturn([]);
        $saved = [];
        $this->callbacks->method('saveEntity')->willReturnCallback(function (DispatchCallback $c) use (&$saved): void {
            $saved[] = $c;
        });

        $this->tracker->adopt(55, [DispatchTracking::REF_EMAIL => '4001'], $this->now, 'production', true, ['logSendEmailId' => 4001]);

        $this->assertCount(1, $saved);
        $this->assertSame(DispatchCallback::DISPATCH_SUCCESS, $saved[0]->getOutcome());
        $this->assertSame(['logSendEmailId' => 4001], $saved[0]->getResponse());
    }

    public function testAdoptOfARefusedCallCreatesAFailedEntryWithItsResponse(): void
    {
        $this->trackings->method('findByRefs')->willReturn([]);
        $this->callbacks->method('findByCampaignLogId')->willReturn([]);
        $saved = null;
        $this->callbacks->method('saveEntity')->willReturnCallback(function (DispatchCallback $c) use (&$saved): void {
            $saved = $c;
        });

        $this->tracker->adopt(55, [], $this->now, 'production', false, ['error' => 'x']);

        $this->assertSame(DispatchCallback::DISPATCH_FAILED, $saved->getOutcome());
        $this->assertSame(['error' => 'x'], $saved->getResponse());
    }

    public function testAdoptOfATestStepCreatesASimulatedEntry(): void
    {
        $this->trackings->method('findByRefs')->willReturn([]);
        $this->callbacks->method('findByCampaignLogId')->willReturn([]);
        $saved = null;
        $this->callbacks->method('saveEntity')->willReturnCallback(function (DispatchCallback $c) use (&$saved): void {
            $saved = $c;
        });

        $this->tracker->adopt(55, [], $this->now, 'test');

        $this->assertSame(DispatchCallback::DISPATCH_TEST, $saved->getOutcome());
    }

    public function testApplyKeepsAMessageThatIsAnObjectAndShowsItAsTextOnTheRow(): void
    {
        $row = $this->existing(DispatchTracking::REF_EMAIL, '4001', 7);
        $saved = null;
        $this->trackings->method('findOneByRef')->willReturn($row);
        $this->callbacks->method('saveEntity')->willReturnCallback(function (DispatchCallback $c) use (&$saved): void {
            $saved = $c;
        });

        $this->tracker->apply([
            ['refType' => 'logSendEmailId', 'refValue' => '4001', 'outcome' => 'error', 'message' => ['code' => 400], 'body' => []],
        ], $this->now);

        $this->assertSame(['code' => 400], $saved->getMessage());
        $this->assertSame("{\n    \"code\": 400\n}", $row->getMessage());
    }
}
