<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\EventListener;

use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\Event\CampaignEvent;
use MauticPlugin\N8nDispatchBundle\Service\EventNameSync;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Before a campaign is saved, names each action of this plugin after the communication it dispatches
 * (EventNameSync), so the contact's History reads "<communication> / <campaign>".
 */
class EventNameSubscriber implements EventSubscriberInterface
{
    public function __construct(private EventNameSync $sync)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CampaignEvents::CAMPAIGN_PRE_SAVE => 'onCampaignPreSave',
        ];
    }

    public function onCampaignPreSave(CampaignEvent $event): void
    {
        foreach ($event->getCampaign()->getEvents() as $campaignEvent) {
            if ($campaignEvent instanceof Event && EventNameSync::handles((string) $campaignEvent->getType())) {
                $this->sync->sync($campaignEvent);
            }
        }
    }
}
