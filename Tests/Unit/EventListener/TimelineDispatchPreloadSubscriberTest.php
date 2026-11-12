<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\EventListener;

use Mautic\LeadBundle\Event\LeadTimelineEvent;
use Mautic\LeadBundle\LeadEvents;
use MauticPlugin\N8nDispatchBundle\EventListener\TimelineDispatchPreloadSubscriber;
use MauticPlugin\N8nDispatchBundle\Service\DispatchListReader;
use MauticPlugin\N8nDispatchBundle\Twig\DispatchExtension;
use PHPUnit\Framework\TestCase;

class TimelineDispatchPreloadSubscriberTest extends TestCase
{
    public function testItRunsAfterCoreAddedTheCampaignEvents(): void
    {
        $priority = TimelineDispatchPreloadSubscriber::getSubscribedEvents()[LeadEvents::TIMELINE_ON_GENERATE][1];

        $this->assertLessThan(0, $priority);
    }

    public function testTheCampaignCardsOfThePageAreReadInOneQuery(): void
    {
        $reader = $this->createMock(DispatchListReader::class);
        $reader->expects($this->once())->method('findMany')->with($this->identicalTo([3, 4, 5]))->willReturn([]);

        $this->subscriber($reader)->preload($this->event(true, [
            ['event' => 'campaign.event', 'extra' => ['log' => ['log_id' => '3']]],
            ['event' => 'campaign.event.scheduled', 'extra' => ['log' => ['log_id' => 4]]],
            ['event' => 'campaign.event', 'extra' => ['log' => ['log_id' => 3]]],
            ['event' => 'email.sent', 'extra' => ['log' => ['log_id' => 99]]],
            ['event' => 'campaign.event', 'extra' => []],
            ['event' => 'campaign.event', 'extra' => ['log' => ['log_id' => 5]]],
        ]));
    }

    public function testAPageWithoutCampaignCardsReadsNothing(): void
    {
        $reader = $this->createMock(DispatchListReader::class);
        $reader->expects($this->never())->method('findMany');

        $this->subscriber($reader)->preload($this->event(true, [['event' => 'email.sent', 'extra' => []]]));
    }

    public function testTheApiAndTheCountsReadNothing(): void
    {
        $reader = $this->createMock(DispatchListReader::class);
        $reader->expects($this->never())->method('findMany');

        $this->subscriber($reader)->preload($this->event(false, [['event' => 'campaign.event', 'extra' => ['log' => ['log_id' => 3]]]]));
    }

    private function subscriber(DispatchListReader $reader): TimelineDispatchPreloadSubscriber
    {
        return new TimelineDispatchPreloadSubscriber(new DispatchExtension($reader));
    }

    /**
     * @param list<array<string, mixed>> $entries
     */
    private function event(bool $forTimeline, array $entries): LeadTimelineEvent
    {
        $event = $this->createMock(LeadTimelineEvent::class);
        $event->method('isForTimeline')->willReturn($forTimeline);
        $event->method('getEvents')->willReturn($entries);

        return $event;
    }
}
