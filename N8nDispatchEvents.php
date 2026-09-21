<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle;

final class N8nDispatchEvents
{
    /**
     * Fired when a contact reaches the "Send via n8n (Email)" campaign action.
     * No listener is registered for this yet — the action exists so the
     * campaign builder form (email picker + per-template variable inputs)
     * can be built and tested on its own, before the actual dispatch-to-n8n
     * logic is wired up in a later increment.
     */
    public const ON_CAMPAIGN_TRIGGER_EMAIL_SEND = 'mautic.n8ndispatch.on_campaign_trigger_email_send';

    /**
     * Fired when a contact reaches the "Send via n8n (SMS)" campaign action.
     * No listener is registered for this yet — same "form only" increment
     * ON_CAMPAIGN_TRIGGER_EMAIL_SEND started as (see its own docblock above).
     */
    public const ON_CAMPAIGN_TRIGGER_SMS_SEND = 'mautic.n8ndispatch.on_campaign_trigger_sms_send';
}
