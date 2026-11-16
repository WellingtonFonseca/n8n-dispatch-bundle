<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service\Alert;

use Mautic\CoreBundle\Form\Type\YesNoButtonGroupType;
use MauticPlugin\N8nDispatchBundle\Service\Alert\AlertSettings;
use MauticPlugin\N8nDispatchBundle\Service\Alert\AlertSettingsForm;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

#[Group('area:alerts')]
#[Group('risk:FLUXO')]
#[Group('risk:UI')]
class AlertSettingsFormTest extends TestCase
{
    public function testItAddsTheSwitchAndTheSevenNumbersWithTheirLimitsAndTheTabMarks(): void
    {
        $added   = [];
        $builder = $this->createMock(FormBuilderInterface::class);
        $builder->method('add')->willReturnCallback(function (string $name, string $type, array $options) use (&$added, $builder) {
            $added[$name] = [$type, $options];

            return $builder;
        });

        AlertSettingsForm::addTo($builder, AlertSettings::fromArray([AlertSettings::KEY_ENABLED => 1, AlertSettings::KEY_INSTANCE_NAME => 'Mautic Vendas', AlertSettings::KEY_BOUNCE_WINDOW => 90, AlertSettings::KEY_FAILING_THRESHOLD => 4]), 'Alertas');

        $this->assertSame(
            [AlertSettings::KEY_ENABLED, AlertSettings::KEY_INSTANCE_NAME, AlertSettings::KEY_WARNING_PERCENT, AlertSettings::KEY_WARNING_MIN, AlertSettings::KEY_BOUNCE_PERCENT, AlertSettings::KEY_BOUNCE_WINDOW, AlertSettings::KEY_BOUNCE_MIN_SENDS, AlertSettings::KEY_FAILING_THRESHOLD, AlertSettings::KEY_FAILING_INTERVAL],
            array_keys($added)
        );
        [$type, $switch] = $added[AlertSettings::KEY_ENABLED];
        $this->assertSame(YesNoButtonGroupType::class, $type);
        $this->assertSame(1, $switch['data']);
        $this->assertSame('Alertas', $switch['attr']['data-tab-label']);
        $this->assertSame('mautic.n8ndispatch.settings.alerts.enabled', $switch['label']);
        $this->assertSame('mautic.n8ndispatch.settings.alerts.enabled.tooltip', $switch['attr']['tooltip']);
        $this->assertSame('n8ndispatchAlertsTab', $switch['attr']['data-onload-callback']);

        foreach ($added as $key => [$type, $options]) {
            $this->assertSame('1', $options['attr']['data-alerts-field'], $key);
        }

        // every number has its own label and tooltip key: a key built wrong would show the raw key on the screen
        $text = 'mautic.n8ndispatch.settings.alerts.';
        foreach ([
            AlertSettings::KEY_WARNING_PERCENT   => 'warning_percent',
            AlertSettings::KEY_WARNING_MIN       => 'warning_min',
            AlertSettings::KEY_BOUNCE_PERCENT    => 'bounce_percent',
            AlertSettings::KEY_BOUNCE_WINDOW     => 'bounce_window',
            AlertSettings::KEY_BOUNCE_MIN_SENDS  => 'bounce_min',
            AlertSettings::KEY_FAILING_THRESHOLD => 'failing_threshold',
            AlertSettings::KEY_FAILING_INTERVAL  => 'failing_interval',
        ] as $key => $name) {
            $this->assertSame($text.$name, $added[$key][1]['label'], $key);
            $this->assertSame($text.$name.'.tooltip', $added[$key][1]['attr']['tooltip'], $key);
        }

        [$type, $instance] = $added[AlertSettings::KEY_INSTANCE_NAME];
        $this->assertSame(TextType::class, $type);
        $this->assertSame('Mautic Vendas', $instance['data']);
        $this->assertFalse($instance['required']);
        $this->assertSame(100, $instance['attr']['maxlength']);
        $this->assertSame(100, $instance['constraints'][0]->max);
        $this->assertSame('mautic.n8ndispatch.settings.alerts.instance_name', $instance['label']);
        $this->assertSame('mautic.n8ndispatch.settings.alerts.instance_name.tooltip', $instance['attr']['tooltip']);
        $this->assertSame('form-control', $instance['attr']['class']);
        $this->assertSame(['class' => 'control-label'], $instance['label_attr']);

        [$type, $window] = $added[AlertSettings::KEY_BOUNCE_WINDOW];
        $this->assertSame(IntegerType::class, $type);
        $this->assertSame(90, $window['data']);
        $this->assertSame([5, 1440], [$window['attr']['min'], $window['attr']['max']]);
        $this->assertSame('mautic.n8ndispatch.settings.alerts.bounce_window', $window['label']);
        $this->assertSame('mautic.n8ndispatch.settings.alerts.bounce_window.tooltip', $window['attr']['tooltip']);
        $this->assertSame('form-control', $window['attr']['class']);
        $this->assertSame(['class' => 'control-label'], $window['label_attr']);
        $this->assertSame(5, $window['constraints'][0]->min);
        $this->assertSame(1440, $window['constraints'][0]->max);
        $this->assertSame([1, 9], [$added[AlertSettings::KEY_WARNING_PERCENT][1]['attr']['min'], $added[AlertSettings::KEY_WARNING_PERCENT][1]['attr']['max']]);
        $this->assertSame([1, 100], [$added[AlertSettings::KEY_BOUNCE_PERCENT][1]['attr']['min'], $added[AlertSettings::KEY_BOUNCE_PERCENT][1]['attr']['max']]);

        [, $threshold] = $added[AlertSettings::KEY_FAILING_THRESHOLD];
        $this->assertSame(4, $threshold['data']);
        $this->assertSame([1, 1000], [$threshold['attr']['min'], $threshold['attr']['max']]);
        $this->assertSame('mautic.n8ndispatch.settings.alerts.failing_threshold', $threshold['label']);
        $this->assertSame('mautic.n8ndispatch.settings.alerts.failing_threshold.tooltip', $threshold['attr']['tooltip']);
        [, $interval] = $added[AlertSettings::KEY_FAILING_INTERVAL];
        $this->assertSame(60, $interval['data']);
        $this->assertSame([15, 1440], [$interval['attr']['min'], $interval['attr']['max']]);
        $this->assertSame('mautic.n8ndispatch.settings.alerts.failing_interval.tooltip', $interval['attr']['tooltip']);
    }

    public function testTheSwitchIsOffUntilTheChannelIsTurnedOn(): void
    {
        $data    = null;
        $builder = $this->createMock(FormBuilderInterface::class);
        $builder->method('add')->willReturnCallback(function (string $name, string $type, array $options) use (&$data, $builder) {
            if (AlertSettings::KEY_ENABLED === $name) {
                $data = $options['data'];
            }

            return $builder;
        });

        AlertSettingsForm::addTo($builder, AlertSettings::fromArray([]), 'Alertas');

        $this->assertSame(0, $data);
    }
}
