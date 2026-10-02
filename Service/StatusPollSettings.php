<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\N8nDispatchBundle\Entity\PollRun;
use MauticPlugin\N8nDispatchBundle\Integration\N8nDispatchIntegration;

/**
 * The status poll's two settings, kept on the plugin's own screen
 * (Settings > Plugins > N8n Dispatch > Features, see
 * N8nDispatchIntegration::appendToForm()): on/off — off until someone turns
 * it on — and how often, in minutes.
 */
class StatusPollSettings
{
    public const KEY_ENABLED = 'status_poll_enabled';
    public const KEY_INTERVAL = 'status_poll_interval';

    public const DEFAULT_INTERVAL = 60;
    public const MIN_INTERVAL     = 5;
    public const MAX_INTERVAL     = 1440;

    public function __construct(private IntegrationHelper $integrationHelper)
    {
    }

    /**
     * @return array{enabled: bool, interval: int}
     */
    public function current(): array
    {
        $integration = $this->integrationHelper->getIntegrationObject(N8nDispatchIntegration::NAME);
        $stored      = $integration ? $integration->getIntegrationSettings()->getFeatureSettings() : [];

        return self::fromArray(is_array($stored) ? $stored : []);
    }

    /**
     * @param array<string, mixed> $featureSettings
     *
     * @return array{enabled: bool, interval: int}
     */
    public static function fromArray(array $featureSettings): array
    {
        $interval = $featureSettings[self::KEY_INTERVAL] ?? null;

        return [
            'enabled'  => (bool) (int) ($featureSettings[self::KEY_ENABLED] ?? 0),
            'interval' => is_numeric($interval)
                ? max(self::MIN_INTERVAL, min(self::MAX_INTERVAL, (int) $interval))
                : self::DEFAULT_INTERVAL,
        ];
    }

    /**
     * True when the status poll is on and, judging by the last scheduled
     * run, the Mautic cron that wakes it up does not seem to be running:
     * nothing started for much longer than the interval. The plugin's
     * screen shows a warning for it. Never stale before the first run.
     */
    public static function isStale(?PollRun $latest, int $intervalMinutes, \DateTimeImmutable $now): bool
    {
        if (null === $latest) {
            return false;
        }

        $limitMinutes = max(3 * $intervalMinutes, 60);

        return $latest->getStartedAt() < $now->modify('-'.$limitMinutes.' minutes');
    }
}
