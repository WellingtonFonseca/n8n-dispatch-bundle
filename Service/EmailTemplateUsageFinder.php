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
 * Email is a core entity, deleted through core's own controllers, so the
 * delete protection is EventListener/EmailDeleteGuardSubscriber (SMS and HSM
 * check it in their own controllers).
 */
class EmailTemplateUsageFinder extends AbstractTemplateUsageFinder
{
    protected const EVENT_TYPE   = 'n8ndispatch.email.send';
    protected const PROPERTY_KEY = 'email';
}
