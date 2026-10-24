<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

/**
 * Finds the campaigns whose "Send via n8n (SMS)" steps point at an
 * SmsTemplate: listed on the template's edit page, and used to refuse
 * deleting a template that is still in use. The mechanism is in
 * Service/AbstractTemplateUsageFinder.php; here only the 'smsTemplate'
 * properties key (Form/Type/SmsDispatchActionType.php) and the
 * 'n8ndispatch.sms.send' event type.
 */
class SmsTemplateUsageFinder extends AbstractTemplateUsageFinder
{
    protected const EVENT_TYPE   = 'n8ndispatch.sms.send';
    protected const PROPERTY_KEY = 'smsTemplate';
}
