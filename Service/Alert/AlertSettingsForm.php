<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service\Alert;

use Mautic\CoreBundle\Form\Type\YesNoButtonGroupType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Range;

/**
 * The fields of the alert channel on the plugin's settings screen. Core gives a plugin no tab of its own, so the
 * fields are built in the Features form and Assets/js/plugin-alerts-tab.js moves the marked ones into a tab "Alertas".
 */
class AlertSettingsForm
{
    /**
     * @param array{enabled: bool, warningPercent: int, warningMinContacts: int, bouncePercent: int, bounceWindow: int, bounceMinSends: int} $current
     */
    public static function addTo(FormBuilderInterface $builder, array $current, string $tabLabel): void
    {
        $builder->add(AlertSettings::KEY_ENABLED, YesNoButtonGroupType::class, [
            'label' => 'mautic.n8ndispatch.settings.alerts.enabled',
            'data'  => $current['enabled'] ? 1 : 0,
            'attr'  => [
                'tooltip'                => 'mautic.n8ndispatch.settings.alerts.enabled.tooltip',
                'data-alerts-field'      => '1',
                'data-tab-label'         => $tabLabel,
                'data-onload-callback'   => 'n8ndispatchAlertsTab',
            ],
        ]);

        self::number($builder, AlertSettings::KEY_WARNING_PERCENT, $current['warningPercent'], AlertSettings::MIN_WARNING_PERCENT, AlertSettings::MAX_WARNING_PERCENT, 'warning_percent');
        self::number($builder, AlertSettings::KEY_WARNING_MIN, $current['warningMinContacts'], AlertSettings::MIN_COUNT, AlertSettings::MAX_COUNT, 'warning_min');
        self::number($builder, AlertSettings::KEY_BOUNCE_PERCENT, $current['bouncePercent'], AlertSettings::MIN_BOUNCE_PERCENT, AlertSettings::MAX_BOUNCE_PERCENT, 'bounce_percent');
        self::number($builder, AlertSettings::KEY_BOUNCE_WINDOW, $current['bounceWindow'], AlertSettings::MIN_BOUNCE_WINDOW, AlertSettings::MAX_BOUNCE_WINDOW, 'bounce_window');
        self::number($builder, AlertSettings::KEY_BOUNCE_MIN_SENDS, $current['bounceMinSends'], AlertSettings::MIN_COUNT, AlertSettings::MAX_COUNT, 'bounce_min');
    }

    private static function number(FormBuilderInterface $builder, string $key, int $value, int $min, int $max, string $text): void
    {
        $builder->add($key, IntegerType::class, [
            'label'       => 'mautic.n8ndispatch.settings.alerts.'.$text,
            'data'        => $value,
            'label_attr'  => ['class' => 'control-label'],
            'attr'        => [
                'class'             => 'form-control',
                'tooltip'           => 'mautic.n8ndispatch.settings.alerts.'.$text.'.tooltip',
                'min'               => $min,
                'max'               => $max,
                'data-alerts-field' => '1',
            ],
            'constraints' => [new Range(min: $min, max: $max)],
        ]);
    }
}
