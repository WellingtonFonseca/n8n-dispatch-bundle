<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service;

use Mautic\CampaignBundle\Entity\LeadEventLog;
use MauticPlugin\N8nDispatchBundle\Entity\DispatchTracking;
use MauticPlugin\N8nDispatchBundle\Service\DispatchOutcomeRecorder;
use MauticPlugin\N8nDispatchBundle\Service\StatusTracker;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DispatchOutcomeRecorderTest extends TestCase
{
    private const IDS = [
        ['body' => 'logSendHsmId', 'meta' => 'logSendHsmId', 'ref' => DispatchTracking::REF_HSM_ID],
        ['body' => 'uuid', 'meta' => 'logSendHsmUuid', 'ref' => DispatchTracking::REF_HSM_UUID],
    ];

    /** @var StatusTracker&MockObject */
    private StatusTracker $tracker;

    /** @var LeadEventLog&MockObject */
    private LeadEventLog $log;

    protected function setUp(): void
    {
        $this->tracker = $this->createMock(StatusTracker::class);
        $this->log     = $this->createMock(LeadEventLog::class);
        $this->log->method('getId')->willReturn(55);
    }

    public function testAnAcceptedAnswerKeepsTheIdsRegistersThemAndStoresTheResponse(): void
    {
        $refs = [DispatchTracking::REF_HSM_ID => 'm1', DispatchTracking::REF_HSM_UUID => 'u1'];
        $this->tracker->expects($this->once())->method('recordDispatch')->with(55, true, $refs, ['logSendHsmId' => 'm1', 'uuid' => 'u1']);
        $this->tracker->expects($this->once())->method('register')->with(DispatchTracking::CHANNEL_HSM, $refs, null, 55);
        $this->log->expects($this->once())->method('appendToMetadata')->with([
            'n8ndispatch'    => ['to' => 'x', 'response' => ['logSendHsmId' => 'm1', 'uuid' => 'u1'], 'httpStatusCode' => 200, 'templateCopyHash' => 'h'],
            'logSendHsmId'   => 'm1',
            'logSendHsmUuid' => 'u1',
        ]);

        DispatchOutcomeRecorder::record($this->tracker, $this->log, DispatchTracking::CHANNEL_HSM, self::IDS, ['to' => 'x'], '{"logSendHsmId":"m1","uuid":"u1"}', 200, true, ['templateCopyHash' => 'h']);
    }

    public function testIdsInsideTheAnswersOwnBodyAreFoundToo(): void
    {
        $this->tracker->expects($this->once())->method('register')->with(DispatchTracking::CHANNEL_HSM, [DispatchTracking::REF_HSM_ID => 'm2', DispatchTracking::REF_HSM_UUID => null], null, 55);

        DispatchOutcomeRecorder::record($this->tracker, $this->log, DispatchTracking::CHANNEL_HSM, self::IDS, [], '{"body":{"logSendHsmId":"m2"}}', 201, true);
    }

    public function testARefusedAnswerIsInTheHistoryButNotRegisteredForThePoll(): void
    {
        $this->tracker->expects($this->once())->method('recordDispatch')->with(55, false, [DispatchTracking::REF_HSM_ID => 'm3', DispatchTracking::REF_HSM_UUID => null], ['logSendHsmId' => 'm3']);
        $this->tracker->expects($this->never())->method('register');

        DispatchOutcomeRecorder::record($this->tracker, $this->log, DispatchTracking::CHANNEL_HSM, self::IDS, [], '{"logSendHsmId":"m3"}', 500, false);
    }

    public function testAnAnswerWithoutIdsIsNotRegisteredAndANonJsonOneIsKeptAsText(): void
    {
        $this->tracker->expects($this->once())->method('recordDispatch')->with(55, true, $this->anything(), 'plain text');
        $this->tracker->expects($this->never())->method('register');
        $this->log->expects($this->once())->method('appendToMetadata')->with(['n8ndispatch' => ['response' => " plain text\n", 'httpStatusCode' => 200]]);

        DispatchOutcomeRecorder::record($this->tracker, $this->log, DispatchTracking::CHANNEL_HSM, self::IDS, [], " plain text\n", 200, true);
    }

    public function testItWorksWithoutAStatusTracker(): void
    {
        $this->log->expects($this->once())->method('appendToMetadata');

        DispatchOutcomeRecorder::record(null, $this->log, DispatchTracking::CHANNEL_HSM, self::IDS, [], '', 200, false);
    }
}
