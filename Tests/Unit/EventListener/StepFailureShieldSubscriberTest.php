<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\EventListener;

use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\Entity\FailedLeadEventLog;
use Mautic\CampaignBundle\Entity\LeadEventLog;
use Mautic\CampaignBundle\Event\FailedEvent;
use Mautic\CampaignBundle\EventCollector\Accessor\Event\AbstractEventAccessor;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\N8nDispatchBundle\Entity\StepFailureRepository;
use MauticPlugin\N8nDispatchBundle\EventListener\StepFailureShieldSubscriber;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[Group('area:alerts')]
#[Group('area:dispatch-email')]
#[Group('area:dispatch-sms')]
#[Group('area:dispatch-hsm')]
#[Group('risk:CRITICO')]
#[Group('risk:FLUXO')]
class StepFailureShieldSubscriberTest extends TestCase
{
    /** @var StepFailureRepository&MockObject */
    private StepFailureRepository $failures;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    private StepFailureShieldSubscriber $shield;

    protected function setUp(): void
    {
        $this->failures = $this->createMock(StepFailureRepository::class);
        $this->logger   = $this->createMock(LoggerInterface::class);
        $this->shield   = new StepFailureShieldSubscriber($this->failures, $this->logger);
    }

    private function failed(string $stepType, ?string $reason = 'N8nDispatch: webhook returned HTTP 500'): FailedEvent
    {
        $campaign = $this->createStub(Campaign::class);
        $campaign->method('getId')->willReturn(7);
        $step = $this->createStub(Event::class);
        $step->method('getId')->willReturn(21);
        $step->method('getType')->willReturn($stepType);
        $step->method('getCampaign')->willReturn($campaign);
        $lead = $this->createStub(Lead::class);
        $lead->method('getId')->willReturn(99);
        $failedLog = $this->createStub(FailedLeadEventLog::class);
        $failedLog->method('getReason')->willReturn($reason);
        $log = $this->createStub(LeadEventLog::class);
        $log->method('getId')->willReturn(555);
        $log->method('getEvent')->willReturn($step);
        $log->method('getLead')->willReturn($lead);
        $log->method('getFailedLog')->willReturn(null === $reason ? null : $failedLog);

        return new FailedEvent($this->createStub(AbstractEventAccessor::class), $log);
    }

    public function testItRunsBeforeEveryOtherListenerOfTheFailedEvent(): void
    {
        $shield = StepFailureShieldSubscriber::getSubscribedEvents()[CampaignEvents::ON_EVENT_FAILED];

        $this->assertSame(['shield', 1000], $shield);
        // core's own listener is at 0 and the plugin's alert listener that remembers the campaign state at 100
        $this->assertGreaterThan(100, $shield[1]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function stepTypes(): iterable
    {
        yield 'email' => ['n8ndispatch.email.send'];
        yield 'sms' => ['n8ndispatch.sms.send'];
        yield 'hsm' => ['n8ndispatch.hsm.send'];
    }

    /**
     * @dataProvider stepTypes
     */
    public function testAFailureOfAStepOfThisPluginIsStoppedAndCounted(string $type): void
    {
        $this->failures->expects($this->once())->method('record')->with(
            21, 99, 7, 555, 'N8nDispatch: webhook returned HTTP 500', $this->isInstanceOf(\DateTimeImmutable::class)
        );
        $event = $this->failed($type);

        $this->shield->shield($event);

        $this->assertTrue($event->isPropagationStopped(), 'core must not count it or unpublish the campaign');
    }

    public function testAFailureOfAStepOfAnotherPluginPassesUntouched(): void
    {
        $this->failures->expects($this->never())->method('record');
        $event = $this->failed('email.send');

        $this->shield->shield($event);

        $this->assertFalse($event->isPropagationStopped(), 'core still handles the steps that are not ours');
    }

    public function testAFailureWithoutAReasonIsCountedWithNone(): void
    {
        $this->failures->expects($this->once())->method('record')->with(21, 99, 7, 555, null, $this->anything());

        $this->shield->shield($this->failed('n8ndispatch.sms.send', null));
    }

    public function testACountThatCannotBeWrittenIsLoggedAndTheEventIsStillStopped(): void
    {
        $this->failures->method('record')->willThrowException(new \RuntimeException('table is gone'));
        $this->logger->expects($this->once())->method('error')->with('N8nDispatch: failure of log 555 (step 21) not counted: table is gone');
        $event = $this->failed('n8ndispatch.email.send');

        $this->shield->shield($event);

        $this->assertTrue($event->isPropagationStopped(), 'a failure to count must not let core unpublish the campaign');
    }
}
