<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
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

    public function testSyncsOnlyTheEventsOfThisPluginWithTheirPreviousProperties(): void
    {
        $mine  = $this->event('n8ndispatch.email.send');
        $other = $this->event('email.send');

        $unitOfWork = $this->createMock(UnitOfWork::class);
        $unitOfWork->method('getOriginalEntityData')->willReturn(['properties' => ['email' => 6]]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($unitOfWork);

        $sync = $this->createMock(EventNameSync::class);
        $sync->expects($this->once())->method('sync')->with($mine, ['email' => 6]);

        $campaign = $this->campaign($mine, $other);

        (new EventNameSubscriber($sync, $em))->onCampaignPreSave(new CampaignEvent($campaign));
    }

    public function testANewEventHasNoPreviousProperties(): void
    {
        $mine = $this->event('n8ndispatch.sms.send');

        $unitOfWork = $this->createMock(UnitOfWork::class);
        $unitOfWork->method('getOriginalEntityData')->willReturn([]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($unitOfWork);

        $sync = $this->createMock(EventNameSync::class);
        $sync->expects($this->once())->method('sync')->with($mine, null);

        $campaign = $this->campaign($mine);

        (new EventNameSubscriber($sync, $em))->onCampaignPreSave(new CampaignEvent($campaign));
    }
}
