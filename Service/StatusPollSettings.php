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
 * it on —, how often in minutes, and how the calls to n8n are made: ids per
 * call and the timeouts.
 */
class StatusPollSettings
{
    public const KEY_ENABLED      = 'status_poll_enabled';
    public const KEY_INTERVAL     = 'status_poll_interval';
    public const KEY_BATCH        = 'status_poll_batch';
    public const KEY_TIMEOUT      = 'status_poll_timeout';
    public const KEY_MAX_DURATION = 'status_poll_max_duration';

    public const DEFAULT_INTERVAL = 60;
    public const MIN_INTERVAL     = 5;
    public const MAX_INTERVAL     = 1440;

    /** Ids asked per call to n8n. */
    public const DEFAULT_BATCH = 100;
    public const MIN_BATCH     = 10;
    public const MAX_BATCH     = 1000;

    /** Seconds without a byte from n8n before a call is given up. */
    public const DEFAULT_TIMEOUT = 180;
    public const MIN_TIMEOUT     = 10;
    public const MAX_TIMEOUT     = 900;

    /** Seconds a whole call may last, never shorter than the timeout. */
    public const DEFAULT_MAX_DURATION = 300;
    public const MAX_MAX_DURATION     = 1800;

    public function __construct(private IntegrationHelper $integrationHelper)
    {
    }

    /**
     * @return array{enabled: bool, interval: int, batch: int, timeout: int, maxDuration: int}
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
     * @return array{enabled: bool, interval: int, batch: int, timeout: int, maxDuration: int}
     */
    public static function fromArray(array $featureSettings): array
    {
        $timeout = self::clamp($featureSettings[self::KEY_TIMEOUT] ?? null, self::MIN_TIMEOUT, self::MAX_TIMEOUT, self::DEFAULT_TIMEOUT);

        return [
            'enabled'     => (bool) (int) ($featureSettings[self::KEY_ENABLED] ?? 0),
            'interval'    => self::clamp($featureSettings[self::KEY_INTERVAL] ?? null, self::MIN_INTERVAL, self::MAX_INTERVAL, self::DEFAULT_INTERVAL),
            'batch'       => self::clamp($featureSettings[self::KEY_BATCH] ?? null, self::MIN_BATCH, self::MAX_BATCH, self::DEFAULT_BATCH),
            'timeout'     => $timeout,
            // a whole call can never be allowed less time than its own idle timeout
            'maxDuration' => max($timeout, self::clamp($featureSettings[self::KEY_MAX_DURATION] ?? null, self::MIN_TIMEOUT, self::MAX_MAX_DURATION, self::DEFAULT_MAX_DURATION)),
        ];
    }

    private static function clamp(mixed $value, int $min, int $max, int $default): int
    {
        return is_numeric($value) ? max($min, min($max, (int) $value)) : $default;
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
