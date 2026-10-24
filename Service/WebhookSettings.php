<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\N8nDispatchBundle\Integration\N8nDispatchIntegration;

/**
 * Where and how a dispatch reaches n8n: the webhook URL and the headers of the
 * integration's settings. The Email, SMS and HSM campaign actions and the
 * resend all need the same two checks (integration enabled, URL set) before
 * the same headers.
 */
final class WebhookSettings
{
    /**
     * @return array{url: string, headers: array<string, string>}|string the webhook, or the DispatchFailureReasons key
     *                                                                   of what is missing (INTEGRATION_DISABLED or WEBHOOK_MISSING)
     */
    public static function forAction(IntegrationHelper $integrationHelper, string $action): array|string
    {
        $integration = $integrationHelper->getIntegrationObject(N8nDispatchIntegration::NAME);

        if (!$integration || !$integration->getIntegrationSettings()->isPublished()) {
            return DispatchFailureReasons::INTEGRATION_DISABLED;
        }

        $keys = $integration->getKeys();
        $url  = trim((string) ($keys['webhook_url'] ?? ''));

        if ('' === $url) {
            return DispatchFailureReasons::WEBHOOK_MISSING;
        }

        $headers = [
            'Content-Type'          => 'application/json',
            'X-N8n-Dispatch-Action' => $action,
        ];
        $token = trim((string) ($keys['webhook_token'] ?? ''));
        if ('' !== $token) {
            $headers['X-N8n-Dispatch-Token'] = $token;
        }

        return ['url' => $url, 'headers' => $headers];
    }
}
