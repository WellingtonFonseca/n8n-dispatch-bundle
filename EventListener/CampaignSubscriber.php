<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\EventListener;

use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Event\CampaignBuilderEvent;
use MauticPlugin\N8nDispatchBundle\Form\Type\EmailDispatchActionType;
use MauticPlugin\N8nDispatchBundle\N8nDispatchEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Registers the "Send via n8n (Email)" Campaign Action. The actual
 * trigger logic lives in CampaignTriggerSubscriber, listening on
 * N8nDispatchEvents::ON_CAMPAIGN_TRIGGER_EMAIL_SEND (registered here as
 * 'batchEventName' — Mautic's current, non-deprecated execution API,
 * dispatched once per batch of contacts as a Mautic\CampaignBundle\
 * Event\PendingEvent; the older 'eventName'/CampaignExecutionEvent path
 * is legacy BC code, deprecated since 2.13).
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
                'batchEventName'  => N8nDispatchEvents::ON_CAMPAIGN_TRIGGER_EMAIL_SEND,
                'formType'        => EmailDispatchActionType::class,
                'channel'         => 'email',
                'channelIdField'  => 'email',
                'template'        => '@N8nDispatch/Event/_email_send.html.twig',
            ]
        );
    }
}
