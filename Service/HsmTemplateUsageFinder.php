<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

/**
 * Finds the campaigns whose "Send via n8n (HSM)" steps point at an
 * HsmTemplate: listed on the template's edit page, and used to refuse
 * deleting a template that is still in use. The mechanism is in
 * Service/AbstractTemplateUsageFinder.php; here only the 'hsmTemplateId'
 * properties key (Form/Type/HsmDispatchActionType.php's own field — which
 * HsmTemplate *entity* a campaign picked, an int; not to be confused with
 * that entity's own 'hsmTemplate' field, a string) and the
 * 'n8ndispatch.hsm.send' event type.
 */
class HsmTemplateUsageFinder extends AbstractTemplateUsageFinder
{
    protected const EVENT_TYPE   = 'n8ndispatch.hsm.send';
    protected const PROPERTY_KEY = 'hsmTemplateId';
}
