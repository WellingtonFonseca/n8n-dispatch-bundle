<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\EventListener;

use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Event\CampaignBuilderEvent;
use MauticPlugin\N8nDispatchBundle\Form\Type\EmailDispatchActionType;
use MauticPlugin\N8nDispatchBundle\N8nDispatchEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Registers the "Send via n8n (Email)" Campaign Action. Only the action
 * itself is registered here — no listener exists yet for
 * N8nDispatchEvents::ON_CAMPAIGN_TRIGGER_EMAIL_SEND, so a contact reaching
 * this step in a live campaign does nothing today. This increment is
 * scoped to the action's own form (email picker + per-template variable
 * inputs), not the dispatch itself.
 */
class CampaignSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            CampaignEvents::CAMPAIGN_ON_BUILD => 'onCampaignBuild',
        ];
    }

    public function onCampaignBuild(CampaignBuilderEvent $event): void
    {
        $event->addAction(
            'n8ndispatch.email.send',
            [
                'label'           => 'mautic.n8ndispatch.campaign.event.email.send',
                'description'     => 'mautic.n8ndispatch.campaign.event.email.send_descr',
                'eventName'       => N8nDispatchEvents::ON_CAMPAIGN_TRIGGER_EMAIL_SEND,
                'formType'        => EmailDispatchActionType::class,
                'channel'         => 'email',
                'channelIdField'  => 'email',
            ]
        );
    }
}
