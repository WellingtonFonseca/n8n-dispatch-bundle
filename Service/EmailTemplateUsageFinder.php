<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

/**
 * Finds the campaigns whose "Send via n8n (Email)" steps point at an
 * Email: listed on the native Email edit page's "Campaigns" tab
 * (EventListener/EmailCampaignsTabSubscriber.php). The mechanism is in
 * Service/AbstractTemplateUsageFinder.php; here only the 'email' properties
 * key (Form/Type/EmailDispatchActionType.php's own picker field) and the
 * 'n8ndispatch.email.send' event type.
 *
 * Unlike SMS/HSM, there's no delete-protection use for this one: Email is
 * a core entity, deleted through core's own EmailController, which this
 * plugin doesn't hook into.
 */
class EmailTemplateUsageFinder extends AbstractTemplateUsageFinder
{
    protected const EVENT_TYPE   = 'n8ndispatch.email.send';
    protected const PROPERTY_KEY = 'email';
}
