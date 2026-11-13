<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

/**
 * The settings of the Mirror resync job, on the plugin's Features tab (MirrorSettingsForm). MirrorSendSwitch turns the
 * job on or off.
 */
class MirrorResyncSettings
{
    public const KEY_INTERVAL      = 'mirror_resync_interval';
    public const KEY_BATCH         = 'mirror_resync_batch';
    public const KEY_DELAY         = 'mirror_resync_delay';
    public const KEY_REFRESH_HOURS = 'mirror_resync_refresh_hours';

    public const DEFAULT_INTERVAL = 120;
    public const MIN_INTERVAL     = 30;
    public const MAX_INTERVAL     = 1440;

    /** Emails per run, one call each. */
    public const DEFAULT_BATCH = 100;
    public const MIN_BATCH     = 10;
    public const MAX_BATCH     = 1000;

    /** Milliseconds between two calls to n8n. */
    public const DEFAULT_DELAY = 2000;
    public const MIN_DELAY     = 0;
    public const MAX_DELAY     = 60000;

    /** An Email in sync is sent again after this many hours. */
    public const DEFAULT_REFRESH_HOURS = 24;
    public const MIN_REFRESH_HOURS     = 6;
    public const MAX_REFRESH_HOURS     = 168;

    public function __construct(private FeatureSettings $featureSettings)
    {
    }

    /**
     * @return array{interval: int, batch: int, delay: int, refreshHours: int}
     */
    public function current(): array
    {
        return self::fromArray($this->featureSettings->all());
    }

    /**
     * @param array<string, mixed> $featureSettings
     *
     * @return array{interval: int, batch: int, delay: int, refreshHours: int}
     */
    public static function fromArray(array $featureSettings): array
    {
        return [
            'interval'     => SettingValue::clamp($featureSettings[self::KEY_INTERVAL] ?? null, self::MIN_INTERVAL, self::MAX_INTERVAL, self::DEFAULT_INTERVAL),
            'batch'        => SettingValue::clamp($featureSettings[self::KEY_BATCH] ?? null, self::MIN_BATCH, self::MAX_BATCH, self::DEFAULT_BATCH),
            'delay'        => SettingValue::clamp($featureSettings[self::KEY_DELAY] ?? null, self::MIN_DELAY, self::MAX_DELAY, self::DEFAULT_DELAY),
            'refreshHours' => SettingValue::clamp($featureSettings[self::KEY_REFRESH_HOURS] ?? null, self::MIN_REFRESH_HOURS, self::MAX_REFRESH_HOURS, self::DEFAULT_REFRESH_HOURS),
        ];
    }
}
