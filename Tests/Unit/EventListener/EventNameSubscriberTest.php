<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\EventListener;

use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\Event\CampaignEvent;
use MauticPlugin\N8nDispatchBundle\EventListener\EventNameSubscriber;
use MauticPlugin\N8nDispatchBundle\Service\EventNameSync;
use PHPUnit\Framework\TestCase;

class EventNameSubscriberTest extends TestCase
{
    private function campaign(Event ...$events): Campaign
    {
        $campaign = new Campaign();
        foreach ($events as $event) {
            $campaign->addEvent($event->getId() ?? spl_object_id($event), $event);
        }

        return $campaign;
    }

    private function event(string $type): Event
    {
        $event = new Event();
        $event->setType($type);
        $event->setProperties(['email' => 7]);

        return $event;
    }

    public function testSubscribesToCampaignPreSave(): void
    {
        $this->assertArrayHasKey(CampaignEvents::CAMPAIGN_PRE_SAVE, EventNameSubscriber::getSubscribedEvents());
    }

    public function testSyncsOnlyTheEventsOfThisPlugin(): void
    {
        $mine  = $this->event('n8ndispatch.email.send');
        $other = $this->event('email.send');

        $sync = $this->createMock(EventNameSync::class);
        $sync->expects($this->once())->method('sync')->with($mine);

        $campaign = $this->campaign($mine, $other);

        (new EventNameSubscriber($sync))->onCampaignPreSave(new CampaignEvent($campaign));
    }
}
