<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\EventListener;

use Doctrine\ORM\EntityManagerInterface;
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
    public function __construct(private EventNameSync $sync, private EntityManagerInterface $em)
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
                $this->sync->sync($campaignEvent, $this->previousProperties($campaignEvent));
            }
        }
    }

    /**
     * What the event's properties were in the database (the template it had before this save), if it is already saved.
     *
     * @return array<string, mixed>|null
     */
    private function previousProperties(Event $event): ?array
    {
        $original = $this->em->getUnitOfWork()->getOriginalEntityData($event)['properties'] ?? null;

        return is_array($original) ? $original : null;
    }
}
