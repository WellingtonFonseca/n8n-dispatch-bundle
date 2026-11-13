<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

use Symfony\Component\Form\FormBuilderInterface;

/**
 * The Mirror fields: the send switch, the resync job fields and its read-only "Last run". Core gives a plugin no tab of
 * its own, so they are built in the Features form and Assets/js/plugin-mirror-tab.js moves them into a tab "Mirror".
 */
class MirrorSettingsForm
{
    /**
     * @param array{interval: int, batch: int, delay: int, refreshHours: int} $current
     */
    public static function addTo(FormBuilderInterface $builder, bool $sendOn, array $current, string $lastRun, string $tabLabel): void
    {
        $text = 'mautic.n8ndispatch.settings.mirror_resync.';
        SettingsFields::switch($builder, MirrorSendSwitch::KEY_ENABLED, $sendOn, 'mautic.n8ndispatch.settings.mirror_send.enabled', [
            'data-tab-label'       => $tabLabel,
            'data-onload-callback' => 'n8ndispatchMirrorTab',
        ]);
        SettingsFields::number($builder, MirrorResyncSettings::KEY_INTERVAL, $current['interval'], MirrorResyncSettings::MIN_INTERVAL, MirrorResyncSettings::MAX_INTERVAL, $text.'interval');
        SettingsFields::number($builder, MirrorResyncSettings::KEY_BATCH, $current['batch'], MirrorResyncSettings::MIN_BATCH, MirrorResyncSettings::MAX_BATCH, $text.'batch');
        SettingsFields::number($builder, MirrorResyncSettings::KEY_DELAY, $current['delay'], MirrorResyncSettings::MIN_DELAY, MirrorResyncSettings::MAX_DELAY, $text.'delay');
        SettingsFields::number($builder, MirrorResyncSettings::KEY_REFRESH_HOURS, $current['refreshHours'], MirrorResyncSettings::MIN_REFRESH_HOURS, MirrorResyncSettings::MAX_REFRESH_HOURS, $text.'refresh_hours');
        SettingsFields::lastRun($builder, 'mirror_resync_last_run', $text.'last_run', $lastRun);
    }
}
