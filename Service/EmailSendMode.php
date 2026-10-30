<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\N8nDispatchBundle\Integration\N8nDispatchIntegration;

/**
 * Where the Email of a campaign step goes out, per Mautic instance: through
 * n8n (the webhook, as always) or straight through Mautic's own mailer. Kept
 * on the plugin's screen (Settings > Plugins > N8N Dispatch > Habilitar/Autenticar,
 * under the token, see N8nDispatchIntegration::appendToForm()), with the other
 * keys of the connection. Only the exact value 'mautic' turns the direct send on: an instance that never saved the field, or holds
 * anything else, keeps going through n8n, so updating the plugin never
 * changes how an instance sends. SMS and HSM always go through n8n.
 */
class EmailSendMode
{
    public const KEY    = 'email_send_mode';
    public const N8N    = 'n8n';
    public const MAUTIC = 'mautic';

    public function __construct(private IntegrationHelper $integrationHelper)
    {
    }

    public function current(): string
    {
        $integration = $this->integrationHelper->getIntegrationObject(N8nDispatchIntegration::NAME);
        $stored      = $integration ? $integration->getKeys() : [];

        return self::fromArray(is_array($stored) ? $stored : []);
    }

    public function isDirect(): bool
    {
        return self::MAUTIC === $this->current();
    }

    /**
     * @param array<string, mixed> $keys
     */
    public static function fromArray(array $keys): string
    {
        return self::MAUTIC === ($keys[self::KEY] ?? null) ? self::MAUTIC : self::N8N;
    }
}
