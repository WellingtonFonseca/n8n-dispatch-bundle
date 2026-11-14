<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\EventListener;

use Mautic\LeadBundle\Event\LeadTimelineEvent;
use Mautic\LeadBundle\LeadEvents;
use MauticPlugin\N8nDispatchBundle\EventListener\TimelineDispatchQueueSubscriber;
use MauticPlugin\N8nDispatchBundle\Service\DispatchListReader;
use MauticPlugin\N8nDispatchBundle\Twig\DispatchExtension;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('area:dispatch-history')]
#[Group('risk:DADOS')]
class TimelineDispatchQueueSubscriberTest extends TestCase
{
    public function testItRunsAfterCoreAddedTheCampaignEvents(): void
    {
        $priority = TimelineDispatchQueueSubscriber::getSubscribedEvents()[LeadEvents::TIMELINE_ON_GENERATE][1];

        $this->assertLessThan(0, $priority);
    }

    public function testTheDispatchCardsOfThePageAreReadInOneQueryWhenTheFirstRenders(): void
    {
        $reader = $this->createMock(DispatchListReader::class);
        $reader->expects($this->once())->method('findMany')->with($this->callback(static function (array $ids): bool {
            sort($ids);

            return [3, 4, 5] === $ids; // the real event groups the entries by type: the order is not the page's
        }))->willReturn([]);
        $reader->expects($this->never())->method('find');
        $extension = new DispatchExtension($reader);

        (new TimelineDispatchQueueSubscriber($extension))->queueDispatchCards($this->event(true, [
            $this->card('campaign.event', '3', 'n8ndispatch.email.send'),
            $this->card('campaign.event.scheduled', 4, 'n8ndispatch.sms.send'),
            $this->card('campaign.event', 3, 'n8ndispatch.email.send'),
            ['event' => 'campaign.event', 'eventType' => 'Campanha', 'extra' => []],
            $this->card('campaign.event', 5, 'n8ndispatch.hsm.send'),
        ]));

        $this->assertNull($extension->dispatchOf(4));
        $this->assertNull($extension->dispatchOf(5), 'read with the first one');
    }

    public function testACardOfAnotherStepTypeIsNotQueued(): void
    {
        $reader = $this->createMock(DispatchListReader::class);
        $reader->expects($this->never())->method('findMany');
        $reader->expects($this->once())->method('find')->with(7)->willReturn(null);
        $extension = new DispatchExtension($reader);

        // core's own email step, a condition: not one of ours
        (new TimelineDispatchQueueSubscriber($extension))->queueDispatchCards($this->event(true, [$this->card('campaign.event', 7, 'email.send')]));

        $this->assertNull($extension->dispatchOf(7));
    }

    public function testTheApiIsNotQueued(): void
    {
        $reader = $this->createMock(DispatchListReader::class);
        $reader->expects($this->never())->method('findMany');
        $reader->expects($this->once())->method('find')->with(3)->willReturn(['id' => 3]);
        $extension = new DispatchExtension($reader);

        (new TimelineDispatchQueueSubscriber($extension))->queueDispatchCards($this->event(false, [$this->card('campaign.event', 3, 'n8ndispatch.email.send')]));

        $this->assertSame(['id' => 3], $extension->dispatchOf(3), 'not queued: the card reads its own');
    }

    /**
     * @return array<string, mixed> a Timeline entry as core's campaign listener adds it: its log carries the step type
     */
    private function card(string $eventKey, int|string $logId, string $stepType): array
    {
        return ['event' => $eventKey, 'eventType' => 'Campanha', 'extra' => ['log' => ['log_id' => $logId, 'type' => $stepType]]];
    }

    /**
     * @param list<array<string, mixed>> $entries
     */
    private function event(bool $forTimeline, array $entries): LeadTimelineEvent
    {
        // Mautic 7: LeadTimelineEvent is final, so a real one holds the entries as core's listeners add them
        $event = new LeadTimelineEvent(null, [], null, 1, 25, $forTimeline);
        foreach ($entries as $entry) {
            $event->addEvent($entry + ['timestamp' => new \DateTime('2026-11-12 10:00')]);
        }

        return $event;
    }
}
