<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service\Alert;

use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\N8nDispatchBundle\Integration\N8nDispatchIntegration;

/**
 * The settings of the alert channel, kept on the plugin's screen (Settings > Plugins > N8N Dispatch > tab Alertas, drawn
 * by N8nDispatchIntegration::appendToForm()). The channel is off until someone turns it on.
 */
class AlertSettings
{
    public const KEY_ENABLED            = 'alerts_enabled';
    public const KEY_INSTANCE_NAME      = 'alert_instance_name';
    public const KEY_WARNING_PERCENT    = 'alert_warning_percent';
    public const KEY_WARNING_MIN        = 'alert_warning_min_contacts';
    public const KEY_BOUNCE_PERCENT     = 'alert_bounce_percent';
    public const KEY_BOUNCE_WINDOW      = 'alert_bounce_window_minutes';
    public const KEY_BOUNCE_MIN_SENDS   = 'alert_bounce_min_sends';

    /** Core unpublishes a campaign at 10% of failed contacts, so a warning must come below it. */
    public const DEFAULT_WARNING_PERCENT = 5;
    public const MIN_WARNING_PERCENT     = 1;
    public const MAX_WARNING_PERCENT     = 9;

    /** Under this many contacts a campaign falls before a warning means anything. */
    public const DEFAULT_WARNING_MIN = 20;
    public const MIN_COUNT           = 1;
    public const MAX_COUNT           = 100000;

    public const DEFAULT_BOUNCE_PERCENT = 10;
    public const MIN_BOUNCE_PERCENT     = 1;
    public const MAX_BOUNCE_PERCENT     = 100;

    public const DEFAULT_BOUNCE_WINDOW = 60;
    public const MIN_BOUNCE_WINDOW     = 5;
    public const MAX_BOUNCE_WINDOW     = 1440;

    public const DEFAULT_BOUNCE_MIN_SENDS = 20;

    public const MAX_INSTANCE_NAME_LENGTH = 100;

    public function __construct(private IntegrationHelper $integrationHelper)
    {
    }

    /**
     * @return array{enabled: bool, instanceName: string, warningPercent: int, warningMinContacts: int, bouncePercent: int, bounceWindow: int, bounceMinSends: int}
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
     * @return array{enabled: bool, instanceName: string, warningPercent: int, warningMinContacts: int, bouncePercent: int, bounceWindow: int, bounceMinSends: int}
     */
    public static function fromArray(array $featureSettings): array
    {
        return [
            'enabled'            => (bool) (int) ($featureSettings[self::KEY_ENABLED] ?? 0),
            'instanceName'       => self::instanceName($featureSettings[self::KEY_INSTANCE_NAME] ?? null),
            'warningPercent'     => self::clamp($featureSettings[self::KEY_WARNING_PERCENT] ?? null, self::MIN_WARNING_PERCENT, self::MAX_WARNING_PERCENT, self::DEFAULT_WARNING_PERCENT),
            'warningMinContacts' => self::clamp($featureSettings[self::KEY_WARNING_MIN] ?? null, self::MIN_COUNT, self::MAX_COUNT, self::DEFAULT_WARNING_MIN),
            'bouncePercent'      => self::clamp($featureSettings[self::KEY_BOUNCE_PERCENT] ?? null, self::MIN_BOUNCE_PERCENT, self::MAX_BOUNCE_PERCENT, self::DEFAULT_BOUNCE_PERCENT),
            'bounceWindow'       => self::clamp($featureSettings[self::KEY_BOUNCE_WINDOW] ?? null, self::MIN_BOUNCE_WINDOW, self::MAX_BOUNCE_WINDOW, self::DEFAULT_BOUNCE_WINDOW),
            'bounceMinSends'     => self::clamp($featureSettings[self::KEY_BOUNCE_MIN_SENDS] ?? null, self::MIN_COUNT, self::MAX_COUNT, self::DEFAULT_BOUNCE_MIN_SENDS),
        ];
    }

    private static function instanceName(mixed $value): string
    {
        return is_string($value) ? mb_substr(trim($value), 0, self::MAX_INSTANCE_NAME_LENGTH) : '';
    }

    private static function clamp(mixed $value, int $min, int $max, int $default): int
    {
        return is_numeric($value) ? max($min, min($max, (int) $value)) : $default;
    }
}
