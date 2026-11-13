<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

/**
 * The switch "Enviar ao Mirror os Emails" on the Features tab. Off turns off the send on Email save and the resync
 * job. On by default: a missing key means on. This class is the only reader of the switch.
 */
class MirrorSendSwitch
{
    public const KEY_ENABLED = 'mirror_send_enabled';

    public function __construct(private FeatureSettings $featureSettings)
    {
    }

    public function isOn(): bool
    {
        return self::fromArray($this->featureSettings->all());
    }

    /**
     * @param array<string, mixed> $featureSettings
     */
    public static function fromArray(array $featureSettings): bool
    {
        return SettingValue::flag($featureSettings[self::KEY_ENABLED] ?? 1);
    }
}
