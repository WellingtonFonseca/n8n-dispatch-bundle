<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle;

final class N8nDispatchEvents
{
    /**
     * Fired when a contact reaches the "Send Email (N8N)" campaign
     * action — see EventListener/CampaignTriggerSubscriber.php.
     */
    public const ON_CAMPAIGN_TRIGGER_EMAIL_SEND = 'mautic.n8ndispatch.on_campaign_trigger_email_send';

    /**
     * Fired when a contact reaches the "Send SMS (N8N)" campaign
     * action — see EventListener/SmsCampaignTriggerSubscriber.php.
     */
    public const ON_CAMPAIGN_TRIGGER_SMS_SEND = 'mautic.n8ndispatch.on_campaign_trigger_sms_send';

    /**
     * Fired when a contact reaches the "Send HSM (N8N)" campaign
     * action — see EventListener/HsmCampaignTriggerSubscriber.php.
     */
    public const ON_CAMPAIGN_TRIGGER_HSM_SEND = 'mautic.n8ndispatch.on_campaign_trigger_hsm_send';
}
