<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\N8nDispatchBundle\Integration\N8nDispatchIntegration;

/**
 * The one reader of the plugin's Features tab (Settings > Plugins > N8N Dispatch). The status poll, the Mirror resync
 * and the alerts each turn the stored array into their own values; none of them reads the integration itself.
 */
class FeatureSettings
{
    public function __construct(private IntegrationHelper $integrationHelper)
    {
    }

    /**
     * @return array<string, mixed> empty when the plugin has no integration yet
     */
    public function all(): array
    {
        $integration = $this->integrationHelper->getIntegrationObject(N8nDispatchIntegration::NAME);
        $stored      = $integration ? $integration->getIntegrationSettings()->getFeatureSettings() : [];

        return is_array($stored) ? $stored : [];
    }
}
