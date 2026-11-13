<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service\Alert;

use MauticPlugin\N8nDispatchBundle\Service\SettingsFields;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Length;

/**
 * The fields of the alert channel on the plugin's settings screen. Core gives a plugin no tab of its own, so the
 * fields are built in the Features form and Assets/js/plugin-alerts-tab.js moves the marked ones into a tab "Alertas".
 */
class AlertSettingsForm
{
    /**
     * @param array{enabled: bool, instanceName: string, warningPercent: int, warningMinContacts: int, bouncePercent: int, bounceWindow: int, bounceMinSends: int} $current
     */
    public static function addTo(FormBuilderInterface $builder, array $current, string $tabLabel): void
    {
        $text  = 'mautic.n8ndispatch.settings.alerts.';
        $inTab = ['data-alerts-field' => '1'];

        SettingsFields::switch($builder, AlertSettings::KEY_ENABLED, $current['enabled'], $text.'enabled', $inTab + [
            'data-tab-label'       => $tabLabel,
            'data-onload-callback' => 'n8ndispatchAlertsTab',
        ]);

        $builder->add(AlertSettings::KEY_INSTANCE_NAME, TextType::class, [
            'label'       => $text.'instance_name',
            'data'        => $current['instanceName'],
            'required'    => false,
            'label_attr'  => ['class' => 'control-label'],
            'attr'        => [
                'class'             => 'form-control',
                'tooltip'           => $text.'instance_name.tooltip',
                'maxlength'         => AlertSettings::MAX_INSTANCE_NAME_LENGTH,
                'data-alerts-field' => '1',
            ],
            'constraints' => [new Length(max: AlertSettings::MAX_INSTANCE_NAME_LENGTH)],
        ]);

        SettingsFields::number($builder, AlertSettings::KEY_WARNING_PERCENT, $current['warningPercent'], AlertSettings::MIN_WARNING_PERCENT, AlertSettings::MAX_WARNING_PERCENT, $text.'warning_percent', $inTab);
        SettingsFields::number($builder, AlertSettings::KEY_WARNING_MIN, $current['warningMinContacts'], AlertSettings::MIN_COUNT, AlertSettings::MAX_COUNT, $text.'warning_min', $inTab);
        SettingsFields::number($builder, AlertSettings::KEY_BOUNCE_PERCENT, $current['bouncePercent'], AlertSettings::MIN_BOUNCE_PERCENT, AlertSettings::MAX_BOUNCE_PERCENT, $text.'bounce_percent', $inTab);
        SettingsFields::number($builder, AlertSettings::KEY_BOUNCE_WINDOW, $current['bounceWindow'], AlertSettings::MIN_BOUNCE_WINDOW, AlertSettings::MAX_BOUNCE_WINDOW, $text.'bounce_window', $inTab);
        SettingsFields::number($builder, AlertSettings::KEY_BOUNCE_MIN_SENDS, $current['bounceMinSends'], AlertSettings::MIN_COUNT, AlertSettings::MAX_COUNT, $text.'bounce_min', $inTab);
    }
}
