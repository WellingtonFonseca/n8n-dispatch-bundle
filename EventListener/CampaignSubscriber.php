<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\EventListener;

use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Event\CampaignBuilderEvent;
use MauticPlugin\N8nDispatchBundle\Form\Type\EmailDispatchActionType;
use MauticPlugin\N8nDispatchBundle\Form\Type\SmsDispatchActionType;
use MauticPlugin\N8nDispatchBundle\N8nDispatchEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Registers the "Send via n8n (Email)" and "Send via n8n (SMS)" Campaign
 * Actions. The actual trigger logic lives in CampaignTriggerSubscriber
 * (Email) and SmsCampaignTriggerSubscriber (SMS), each listening on its
 * own N8nDispatchEvents::ON_CAMPAIGN_TRIGGER_* constant (registered here
 * as 'batchEventName' — Mautic's current, non-deprecated execution API,
 * dispatched once per batch of contacts as a Mautic\CampaignBundle\
 * Event\PendingEvent; the older 'eventName'/CampaignExecutionEvent path
 * is legacy BC code, deprecated since 2.13). The SMS action has no
 * 'channel'/'channelIdField' (no Mautic SMS entity is picked, so there's
 * nothing to report a channel id for — same as core's own entity-less
 * actions, e.g. LeadBundle's "Add to DNC"), but reuses the same
 * 'template'/'timelineTemplate' as Email — both are generic enough
 * (status badge, and a Body/Response Timeline card keyed off whatever
 * fields the dispatching listener actually put in 'n8ndispatch', not a
 * fixed per-channel field list) to not need a channel-specific copy.
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
                'label'            => 'mautic.n8ndispatch.campaign.event.email.send',
                'description'      => 'mautic.n8ndispatch.campaign.event.email.send_descr',
                'batchEventName'   => N8nDispatchEvents::ON_CAMPAIGN_TRIGGER_EMAIL_SEND,
                'formType'         => EmailDispatchActionType::class,
                'channel'          => 'email',
                'channelIdField'   => 'email',
                'template'         => '@N8nDispatch/Event/_email_send.html.twig',
                'timelineTemplate' => '@N8nDispatch/SubscribedEvents/Timeline/_email_send.html.twig',
            ]
        );

        $event->addAction(
            'n8ndispatch.sms.send',
            [
                'label'            => 'mautic.n8ndispatch.campaign.event.sms.send',
                'description'      => 'mautic.n8ndispatch.campaign.event.sms.send_descr',
                'batchEventName'   => N8nDispatchEvents::ON_CAMPAIGN_TRIGGER_SMS_SEND,
                'formType'         => SmsDispatchActionType::class,
                'template'         => '@N8nDispatch/Event/_email_send.html.twig',
                'timelineTemplate' => '@N8nDispatch/SubscribedEvents/Timeline/_email_send.html.twig',
            ]
        );
    }
}
