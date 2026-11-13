<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

use Mautic\CoreBundle\Form\Type\YesNoButtonGroupType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Range;

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
        $builder->add(MirrorResyncSettings::KEY_ENABLED, YesNoButtonGroupType::class, [
            'label' => 'mautic.n8ndispatch.settings.mirror_resync.enabled',
            'data'  => $current['enabled'] ? 1 : 0,
            'attr'  => ['tooltip' => 'mautic.n8ndispatch.settings.mirror_resync.enabled.tooltip'],
        ]);

        self::number($builder, MirrorResyncSettings::KEY_INTERVAL, $current['interval'], MirrorResyncSettings::MIN_INTERVAL, MirrorResyncSettings::MAX_INTERVAL, 'interval');
        self::number($builder, MirrorResyncSettings::KEY_BATCH, $current['batch'], MirrorResyncSettings::MIN_BATCH, MirrorResyncSettings::MAX_BATCH, 'batch');
        self::number($builder, MirrorResyncSettings::KEY_DELAY, $current['delay'], MirrorResyncSettings::MIN_DELAY, MirrorResyncSettings::MAX_DELAY, 'delay');
        self::number($builder, MirrorResyncSettings::KEY_REFRESH_HOURS, $current['refreshHours'], MirrorResyncSettings::MIN_REFRESH_HOURS, MirrorResyncSettings::MAX_REFRESH_HOURS, 'refresh_hours');

        $builder->add('mirror_resync_last_run', TextareaType::class, [
            'label'      => 'mautic.n8ndispatch.settings.mirror_resync.last_run',
            'label_attr' => ['class' => 'control-label'],
            'data'       => $lastRun,
            'mapped'     => false,
            'disabled'   => true,
            'required'   => false,
            'attr'       => [
                'class'                => 'form-control n8ndispatch-json-block',
                'rows'                 => max(2, substr_count($lastRun, "\n") + 1),
                'data-onload-callback' => 'n8ndispatchBlockFromTextarea',
            ],
        ]);
    }

    private static function number(FormBuilderInterface $builder, string $key, int $value, int $min, int $max, string $text): void
    {
        $builder->add($key, IntegerType::class, [
            'label'       => 'mautic.n8ndispatch.settings.mirror_resync.'.$text,
            'data'        => $value,
            'label_attr'  => ['class' => 'control-label'],
            'attr'        => [
                'class'   => 'form-control',
                'tooltip' => 'mautic.n8ndispatch.settings.mirror_resync.'.$text.'.tooltip',
                'min'     => $min,
                'max'     => $max,
            ],
            'constraints' => [new Range(min: $min, max: $max)],
        ]);
    }
}
