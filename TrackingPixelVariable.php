<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle;

/**
 * The single source of truth for the reserved open-tracking-pixel
 * variable's name, shared by:
 *   - EventListener\EmailMirrorSyncSubscriber, which auto-injects the
 *     {{...}} token (TOKEN) into every template's customHtml as an
 *     invisible 1x1 <img>;
 *   - EventListener\CampaignTriggerSubscriber, which resolves that token
 *     by always setting KEY in the outgoing 'variables', pointing at the
 *     same Stat/idHash the unsubscribe URL uses;
 *   - Controller\AjaxController, which filters KEY out of the
 *     variable-mapping list shown in the campaign builder, since it's
 *     never something a user maps by hand.
 * Defined once so the three can never drift apart on the literal string.
 * Same pattern as UnsubscribeVariable, kept as its own class (not folded
 * into it) since the two resolve to unrelated Mautic routes/entities.
 */
final class TrackingPixelVariable
{
    public const KEY = 'n8ndispatch_tracking_pixel_url';

    public const TOKEN = '{{'.self::KEY.'}}';
}
