<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

use Symfony\Component\Form\FormBuilderInterface;

/**
 * The fields of the Mirror resync on the plugin's Features tab, under the status poll, and its read-only "Last run".
 */
class MirrorResyncSettingsForm
{
    /**
     * @param array{enabled: bool, interval: int, batch: int, delay: int, refreshHours: int} $current
     */
    public static function addTo(FormBuilderInterface $builder, array $current, string $lastRun): void
    {
        $text = 'mautic.n8ndispatch.settings.mirror_resync.';
        SettingsFields::switch($builder, MirrorResyncSettings::KEY_ENABLED, $current['enabled'], $text.'enabled');
        SettingsFields::number($builder, MirrorResyncSettings::KEY_INTERVAL, $current['interval'], MirrorResyncSettings::MIN_INTERVAL, MirrorResyncSettings::MAX_INTERVAL, $text.'interval');
        SettingsFields::number($builder, MirrorResyncSettings::KEY_BATCH, $current['batch'], MirrorResyncSettings::MIN_BATCH, MirrorResyncSettings::MAX_BATCH, $text.'batch');
        SettingsFields::number($builder, MirrorResyncSettings::KEY_DELAY, $current['delay'], MirrorResyncSettings::MIN_DELAY, MirrorResyncSettings::MAX_DELAY, $text.'delay');
        SettingsFields::number($builder, MirrorResyncSettings::KEY_REFRESH_HOURS, $current['refreshHours'], MirrorResyncSettings::MIN_REFRESH_HOURS, MirrorResyncSettings::MAX_REFRESH_HOURS, $text.'refresh_hours');
        SettingsFields::lastRun($builder, 'mirror_resync_last_run', $text.'last_run', $lastRun);
    }
}
