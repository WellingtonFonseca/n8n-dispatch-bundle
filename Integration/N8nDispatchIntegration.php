<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Integration;

use Mautic\PluginBundle\Integration\AbstractIntegration;

/**
 * Gives this plugin a "Settings > Plugins > N8n Dispatch" screen with two
 * plain text fields (no OAuth) — the webhook URL that receives the
 * {mautic_template_id, html, hash} payload on every Email save, and an
 * auth token sent as a plain "X-N8n-Dispatch-Token" header (matches n8n's
 * Webhook node Header Auth, which is just a literal name/value pair — no
 * Bearer/OAuth2 semantics). Today that URL points at n8n, but nothing
 * here is n8n-specific — it's just "the configured endpoint".
 */
class N8nDispatchIntegration extends AbstractIntegration
{
    public const NAME = 'N8nDispatch';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDisplayName(): string
    {
        return 'N8n Dispatch';
    }

    public function getAuthenticationType(): string
    {
        return 'none';
    }

    /**
     * @return array<string, string>
     */
    public function getRequiredKeyFields(): array
    {
        return [
            'webhook_url'   => 'mautic.integration.n8ndispatch.webhook_url',
            'webhook_token' => 'mautic.integration.n8ndispatch.webhook_token',
        ];
    }
}
