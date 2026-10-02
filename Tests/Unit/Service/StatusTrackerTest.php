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
            ->with('email', 'pending', $this->equalTo(new \DateTimeImmutable('2026-09-25 10:00:00')), 200)
            ->willReturn([]);

        $this->tracker->pending(DispatchTracking::CHANNEL_EMAIL, DispatchTracking::OUTCOME_PENDING, 200, 7, $this->now);
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
}
