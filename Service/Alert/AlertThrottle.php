<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service\Alert;

use Mautic\CoreBundle\Helper\CacheStorageHelper;

/**
 * Lets an alert go out once per key in a cooldown, across the cron processes. A `cache:clear` empties it, which at
 * worst repeats one alert.
 */
class AlertThrottle
{
    private const PREFIX = 'n8ndispatch_alert_';

    public function __construct(private CacheStorageHelper $cache)
    {
    }

    /**
     * True when the key was free and is now taken for `$cooldownSeconds`.
     */
    public function claim(string $key, int $cooldownSeconds): bool
    {
        if ($this->isTaken($key)) {
            return false;
        }

        $this->cache->set(self::PREFIX.$key, 1, $cooldownSeconds);

        return true;
    }

    /**
     * Whether a key is taken now, without taking it: a cheap check before work that only an alert needs.
     */
    public function isTaken(string $key): bool
    {
        return $this->cache->has(self::PREFIX.$key);
    }

    /**
     * Frees a key claimed for an alert that never reached n8n, so the next check tries again.
     */
    public function release(string $key): void
    {
        $this->cache->delete(self::PREFIX.$key);
    }
}
