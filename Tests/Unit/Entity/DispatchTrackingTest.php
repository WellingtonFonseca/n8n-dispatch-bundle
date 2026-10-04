<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Entity;

use MauticPlugin\N8nDispatchBundle\Entity\DispatchTracking;
use PHPUnit\Framework\TestCase;

class DispatchTrackingTest extends TestCase
{
    private function tracking(): DispatchTracking
    {
        return DispatchTracking::create(
            DispatchTracking::CHANNEL_EMAIL,
            DispatchTracking::REF_EMAIL,
            '4298591',
            'group-1',
            new \DateTimeImmutable('2026-10-02 10:00:00')
        );
    }

    public function testStartsPendingWithNoChecks(): void
    {
        $tracking = $this->tracking();

        $this->assertSame(DispatchTracking::OUTCOME_PENDING, $tracking->getOutcome());
        $this->assertSame(0, $tracking->getCheckCount());
        $this->assertNull($tracking->getLastCheckedAt());
        $this->assertNull($tracking->getChangedAt());
    }

    public function testSameOutcomeOnlyCountsTheCheck(): void
    {
        $tracking = $this->tracking();
        $first    = new \DateTimeImmutable('2026-10-02 10:05:00');
        $second   = new \DateTimeImmutable('2026-10-02 10:10:00');

        $this->assertFalse($tracking->applyOutcome(DispatchTracking::OUTCOME_PENDING, null, $first));
        $this->assertFalse($tracking->applyOutcome(DispatchTracking::OUTCOME_PENDING, null, $second));

        $this->assertSame(2, $tracking->getCheckCount());
        $this->assertEquals($second, $tracking->getLastCheckedAt());
        $this->assertNull($tracking->getChangedAt(), 'no change happened, so there is no change date');
    }

    public function testDifferentOutcomeIsAChange(): void
    {
        $tracking = $this->tracking();
        $now      = new \DateTimeImmutable('2026-10-02 10:05:00');

        $this->assertTrue($tracking->applyOutcome(DispatchTracking::OUTCOME_ERROR, 'caixa cheia', $now));

        $this->assertSame(DispatchTracking::OUTCOME_ERROR, $tracking->getOutcome());
        $this->assertSame('caixa cheia', $tracking->getMessage());
        $this->assertEquals($now, $tracking->getChangedAt());
        $this->assertSame(1, $tracking->getCheckCount());
    }

    public function testErrorCanLaterTurnIntoSuccess(): void
    {
        $tracking = $this->tracking();
        $tracking->applyOutcome(DispatchTracking::OUTCOME_ERROR, 'caixa cheia', new \DateTimeImmutable('2026-10-02 10:05:00'));

        $this->assertTrue($tracking->applyOutcome(DispatchTracking::OUTCOME_SUCCESS, null, new \DateTimeImmutable('2026-10-02 11:00:00')));
        $this->assertSame(DispatchTracking::OUTCOME_SUCCESS, $tracking->getOutcome());
    }

    public function testAChangeReplacesTheMessageEvenWithNone(): void
    {
        $tracking = $this->tracking();
        $tracking->applyOutcome(DispatchTracking::OUTCOME_ERROR, 'caixa cheia', new \DateTimeImmutable('2026-10-02 10:05:00'));

        $tracking->applyOutcome(DispatchTracking::OUTCOME_SUCCESS, null, new \DateTimeImmutable('2026-10-02 11:00:00'));

        $this->assertNull($tracking->getMessage(), 'the old error reason must not stay under a success');
    }

    public function testNewMessageWithSameOutcomeUpdatesTheMessageButIsNotAChange(): void
    {
        $tracking = $this->tracking();
        $tracking->applyOutcome(DispatchTracking::OUTCOME_ERROR, 'caixa cheia', new \DateTimeImmutable('2026-10-02 10:05:00'));

        $this->assertFalse($tracking->applyOutcome(DispatchTracking::OUTCOME_ERROR, 'rejeitado pelo servidor', new \DateTimeImmutable('2026-10-02 10:10:00')));
        $this->assertSame('rejeitado pelo servidor', $tracking->getMessage());
    }

    public function testMissingMessageKeepsThePreviousOne(): void
    {
        $tracking = $this->tracking();
        $tracking->applyOutcome(DispatchTracking::OUTCOME_ERROR, 'caixa cheia', new \DateTimeImmutable('2026-10-02 10:05:00'));

        $tracking->applyOutcome(DispatchTracking::OUTCOME_ERROR, null, new \DateTimeImmutable('2026-10-02 10:10:00'));

        $this->assertSame('caixa cheia', $tracking->getMessage());
    }

    public function testRejectsAnUnknownOutcome(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->tracking()->applyOutcome('entregue', null, new \DateTimeImmutable());
    }

    public function testTheCampaignLogIdIsOptionalAndCanBeSetLater(): void
    {
        $tracking = $this->tracking();
        $this->assertNull($tracking->getCampaignLogId());

        $tracking->setCampaignLogId(55);
        $this->assertSame(55, $tracking->getCampaignLogId());

        $withLog = DispatchTracking::create('email', 'logSendEmailId', '1', 'g', new \DateTimeImmutable(), 9);
        $this->assertSame(9, $withLog->getCampaignLogId());
    }
}
