<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\EventListener;

use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\Entity\LeadEventLog;
use Mautic\CampaignBundle\Event\FailedEvent;
use Mautic\CampaignBundle\EventCollector\Accessor\Event\AbstractEventAccessor;
use MauticPlugin\N8nDispatchBundle\EventListener\CampaignFailureAlertSubscriber;
use MauticPlugin\N8nDispatchBundle\Service\Alert\CampaignFailureAlerts;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations] // setUp builds the doubles; each test uses the ones it needs
#[Group('area:alerts')]
#[Group('risk:CRITICO')]
class CampaignFailureAlertSubscriberTest extends TestCase
{
    /** @var CampaignFailureAlerts&MockObject */
    private CampaignFailureAlerts $alerts;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    private bool $published = true;

    protected function setUp(): void
    {
        $this->alerts = $this->createMock(CampaignFailureAlerts::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function failed(): FailedEvent
    {
        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getId')->willReturn(7);
        $campaign->method('isPublished')->willReturnCallback(fn (): bool => $this->published);
        $step = $this->createStub(Event::class);
        $step->method('getCampaign')->willReturn($campaign);
        $log = $this->createStub(LeadEventLog::class);
        $log->method('getEvent')->willReturn($step);

        return new FailedEvent($this->createStub(AbstractEventAccessor::class), $log);
    }

    public function testItRunsBeforeAndAfterCoreHandlesTheFailedStep(): void
    {
        $this->assertSame(
            [['rememberState', 100], ['raiseAlerts', -100]],
            CampaignFailureAlertSubscriber::getSubscribedEvents()[CampaignEvents::ON_EVENT_FAILED]
        );
    }

    public function testACampaignPublishedBeforeCoreAndOffAfterIsReportedAsPublishedBefore(): void
    {
        $subscriber = new CampaignFailureAlertSubscriber($this->alerts, $this->logger);
        $event      = $this->failed();
        $this->alerts->expects($this->once())->method('afterFailure')->with($event->getLog(), true);

        $subscriber->rememberState($event);
        $this->published = false; // core unpublishes between the two listeners
        $subscriber->raiseAlerts($event);
    }

    public function testACampaignOffBeforeCoreIsReportedAsNotPublishedBefore(): void
    {
        $this->published = false;
        $subscriber      = new CampaignFailureAlertSubscriber($this->alerts, $this->logger);
        $event           = $this->failed();
        $this->alerts->expects($this->once())->method('afterFailure')->with($event->getLog(), false);

        $subscriber->rememberState($event);
        $subscriber->raiseAlerts($event);
    }

    public function testWithoutTheEarlyCallTheCampaignCountsAsNotPublishedBefore(): void
    {
        $event = $this->failed();
        $this->alerts->expects($this->once())->method('afterFailure')->with($event->getLog(), false);

        (new CampaignFailureAlertSubscriber($this->alerts, $this->logger))->raiseAlerts($event);
    }

    public function testTheMemoryOfACampaignIsDroppedAfterItsAlert(): void
    {
        $subscriber = new CampaignFailureAlertSubscriber($this->alerts, $this->logger);
        $event      = $this->failed();
        $this->alerts->expects($this->exactly(2))->method('afterFailure')->willReturnCallback(function ($log, bool $before): void {
            static $calls = 0;
            $this->assertSame(1 === ++$calls, $before);
        });

        $subscriber->rememberState($event);
        $subscriber->raiseAlerts($event);
        $subscriber->raiseAlerts($event);
    }

    public function testAFailureOfTheAlertIsLoggedAndNeverReachesCore(): void
    {
        $this->alerts->method('afterFailure')->willThrowException(new \RuntimeException('boom'));
        $this->logger->expects($this->once())->method('error')->with('N8nDispatch: alert of a failed step not raised: boom');

        (new CampaignFailureAlertSubscriber($this->alerts, $this->logger))->raiseAlerts($this->failed());
    }

    public function testAFailureOfTheEarlyCallIsLoggedAndNeverReachesCore(): void
    {
        $campaign = $this->createStub(Campaign::class);
        $campaign->method('getId')->willThrowException(new \RuntimeException('detached'));
        $step = $this->createStub(Event::class);
        $step->method('getCampaign')->willReturn($campaign);
        $log = $this->createStub(LeadEventLog::class);
        $log->method('getEvent')->willReturn($step);
        $this->logger->expects($this->once())->method('error')->with('N8nDispatch: state of a failed step not remembered: detached');

        (new CampaignFailureAlertSubscriber($this->alerts, $this->logger))->rememberState(new FailedEvent($this->createStub(AbstractEventAccessor::class), $log));
    }

    public function testAnEventWithoutACampaignIsLoggedByTheLateCallToo(): void
    {
        $log = $this->createStub(LeadEventLog::class);
        $log->method('getEvent')->willThrowException(new \RuntimeException('no step'));
        $this->alerts->expects($this->never())->method('afterFailure');
        $this->logger->expects($this->once())->method('error')->with('N8nDispatch: alert of a failed step not raised: no step');

        (new CampaignFailureAlertSubscriber($this->alerts, $this->logger))->raiseAlerts(new FailedEvent($this->createStub(AbstractEventAccessor::class), $log));
    }
}
