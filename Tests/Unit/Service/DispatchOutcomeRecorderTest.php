<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service;

use Mautic\CampaignBundle\Entity\LeadEventLog;
use MauticPlugin\N8nDispatchBundle\Entity\DispatchTracking;
use MauticPlugin\N8nDispatchBundle\Service\DispatchOutcomeRecorder;
use MauticPlugin\N8nDispatchBundle\Service\StatusTracker;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class DispatchOutcomeRecorderTest extends TestCase
{
    private const IDS = [
        ['body' => 'logSendHsmId', 'meta' => 'logSendHsmId', 'ref' => DispatchTracking::REF_HSM_ID],
        ['body' => 'uuid', 'meta' => 'logSendHsmUuid', 'ref' => DispatchTracking::REF_HSM_UUID],
    ];

    /** @var StatusTracker&Stub */
    private StatusTracker $tracker;

    /** @var LeadEventLog&Stub */
    private LeadEventLog $log;

    protected function setUp(): void
    {
        $this->tracker = $this->createStub(StatusTracker::class);
        $this->log     = $this->createStub(LeadEventLog::class);
        $this->log->method('getId')->willReturn(55);
    }

    private function expectOnTracker(): void
    {
        $this->tracker = $this->createMock(StatusTracker::class);
    }

    private function expectOnLog(): void
    {
        $this->log = $this->createMock(LeadEventLog::class);
        $this->log->method('getId')->willReturn(55);
    }

    public function testAnAcceptedAnswerKeepsTheIdsRegistersThemAndStoresTheResponse(): void
    {
        $this->expectOnTracker();
        $this->expectOnLog();
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
        $this->expectOnTracker();
        $this->tracker->expects($this->once())->method('register')->with(DispatchTracking::CHANNEL_HSM, [DispatchTracking::REF_HSM_ID => 'm2', DispatchTracking::REF_HSM_UUID => null], null, 55);

        DispatchOutcomeRecorder::record($this->tracker, $this->log, DispatchTracking::CHANNEL_HSM, self::IDS, [], '{"body":{"logSendHsmId":"m2"}}', 201, true);
    }

    public function testARefusedAnswerIsInTheHistoryButNotRegisteredForThePoll(): void
    {
        $this->expectOnTracker();
        $this->tracker->expects($this->once())->method('recordDispatch')->with(55, false, [DispatchTracking::REF_HSM_ID => 'm3', DispatchTracking::REF_HSM_UUID => null], ['logSendHsmId' => 'm3']);
        $this->tracker->expects($this->never())->method('register');

        DispatchOutcomeRecorder::record($this->tracker, $this->log, DispatchTracking::CHANNEL_HSM, self::IDS, [], '{"logSendHsmId":"m3"}', 500, false);
    }

    public function testAnAnswerWithoutIdsIsNotRegisteredAndANonJsonOneIsKeptAsText(): void
    {
        $this->expectOnTracker();
        $this->expectOnLog();
        $this->tracker->expects($this->once())->method('recordDispatch')->with(55, true, $this->anything(), 'plain text');
        $this->tracker->expects($this->never())->method('register');
        $this->log->expects($this->once())->method('appendToMetadata')->with(['n8ndispatch' => ['response' => " plain text\n", 'httpStatusCode' => 200]]);

        DispatchOutcomeRecorder::record($this->tracker, $this->log, DispatchTracking::CHANNEL_HSM, self::IDS, [], " plain text\n", 200, true);
    }

    public function testASingleIdChannelAndAnExtraEntryThatBeatsThePayloadsOwn(): void
    {
        $this->expectOnTracker();
        $this->expectOnLog();
        $ids = [['body' => 'logSendEmailId', 'meta' => 'logSendEmailId', 'ref' => DispatchTracking::REF_EMAIL]];
        $this->tracker->expects($this->once())->method('register')->with(DispatchTracking::CHANNEL_EMAIL, [DispatchTracking::REF_EMAIL => 'e1'], null, 55);
        $this->log->expects($this->once())->method('appendToMetadata')->with([
            'n8ndispatch'    => ['templateCopyHash' => 'real', 'response' => ['logSendEmailId' => 'e1'], 'httpStatusCode' => 200],
            'logSendEmailId' => 'e1',
        ]);

        DispatchOutcomeRecorder::record($this->tracker, $this->log, DispatchTracking::CHANNEL_EMAIL, $ids, ['templateCopyHash' => 'stale'], '{"logSendEmailId":"e1"}', 200, true, ['templateCopyHash' => 'real']);
    }

    public function testAJsonAnswerThatIsNotAnObjectIsKeptAsTextAndHasNoIds(): void
    {
        $this->expectOnTracker();
        $this->expectOnLog();
        $this->tracker->expects($this->once())->method('recordDispatch')->with(55, true, $this->anything(), '5');
        $this->tracker->expects($this->never())->method('register');
        $this->log->expects($this->once())->method('appendToMetadata')->with(['n8ndispatch' => ['response' => '5', 'httpStatusCode' => 200]]);

        DispatchOutcomeRecorder::record($this->tracker, $this->log, DispatchTracking::CHANNEL_HSM, self::IDS, [], '5', 200, true);
    }

    public function testAnIdAtTheTopOfTheAnswerBeatsTheSameKeyInsideItsOwnBody(): void
    {
        $this->expectOnTracker();
        $this->tracker->expects($this->once())->method('register')->with(DispatchTracking::CHANNEL_HSM, [DispatchTracking::REF_HSM_ID => 'top', DispatchTracking::REF_HSM_UUID => null], null, 55);

        DispatchOutcomeRecorder::record($this->tracker, $this->log, DispatchTracking::CHANNEL_HSM, self::IDS, [], '{"logSendHsmId":"top","body":{"logSendHsmId":"nested"}}', 200, true);
    }

    public function testAnAnswerOfOnlyWhitespaceIsKeptAsNothing(): void
    {
        $this->expectOnTracker();
        $this->tracker->expects($this->once())->method('recordDispatch')->with(55, false, $this->anything(), $this->identicalTo(null));

        DispatchOutcomeRecorder::record($this->tracker, $this->log, DispatchTracking::CHANNEL_HSM, self::IDS, [], "  \n ", 500, false);
    }

    public function testAnAnswerWithStatus300IsNotRegisteredForThePoll(): void
    {
        $this->expectOnTracker();
        $this->tracker->expects($this->once())->method('recordDispatch');
        $this->tracker->expects($this->never())->method('register');

        DispatchOutcomeRecorder::record($this->tracker, $this->log, DispatchTracking::CHANNEL_HSM, self::IDS, [], '{"logSendHsmId":"m1"}', 300, false);
    }

    public function testWithoutAStatusTrackerAnAcceptedAnswerWithIdsStillKeepsTheMetadata(): void
    {
        $this->expectOnLog();
        $this->log->expects($this->once())->method('appendToMetadata')->with($this->callback(fn (array $m): bool => 'm1' === ($m['logSendHsmId'] ?? null)));

        DispatchOutcomeRecorder::record(null, $this->log, DispatchTracking::CHANNEL_HSM, self::IDS, [], '{"logSendHsmId":"m1"}', 200, true);
    }

    public function testItWorksWithoutAStatusTracker(): void
    {
        $this->expectOnLog();
        $this->log->expects($this->once())->method('appendToMetadata');

        DispatchOutcomeRecorder::record(null, $this->log, DispatchTracking::CHANNEL_HSM, self::IDS, [], '', 200, false);
    }
}
