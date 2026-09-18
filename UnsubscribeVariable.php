<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle;

/**
 * The single source of truth for the reserved unsubscribe variable's name,
 * shared by:
 *   - EventListener\EmailMirrorSyncSubscriber, which auto-injects the
 *     {{...}} token (TOKEN) into every template's customHtml footer;
 *   - EventListener\CampaignTriggerSubscriber, which resolves that token
 *     by always setting KEY in the outgoing 'variables', overriding
 *     anything a user configured under the same name;
 *   - Controller\AjaxController, which filters KEY out of the
 *     variable-mapping list shown in the campaign builder, since it's
 *     never something a user maps by hand.
 * Defined once so the three can never drift apart on the literal string.
 */
final class UnsubscribeVariable
{
    public const KEY = 'n8ndispatch_unsubscribe_url';

    public const TOKEN = '{{'.self::KEY.'}}';
}
