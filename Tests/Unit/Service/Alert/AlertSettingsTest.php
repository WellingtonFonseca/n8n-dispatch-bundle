<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service\Alert;

use Mautic\PluginBundle\Entity\Integration;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\N8nDispatchBundle\Integration\N8nDispatchIntegration;
use MauticPlugin\N8nDispatchBundle\Service\Alert\AlertSettings;
use PHPUnit\Framework\TestCase;

class AlertSettingsTest extends TestCase
{
    public function testTheChannelIsOffWithTheDefaultsUntilSomeoneTurnsItOn(): void
    {
        $this->assertSame(
            ['enabled' => false, 'warningPercent' => 5, 'warningMinContacts' => 20, 'bouncePercent' => 10, 'bounceWindow' => 60, 'bounceMinSends' => 20],
            AlertSettings::fromArray([])
        );
    }

    public function testTheStoredValuesAreRead(): void
    {
        $current = AlertSettings::fromArray([
            AlertSettings::KEY_ENABLED => '1', AlertSettings::KEY_WARNING_PERCENT => '7', AlertSettings::KEY_WARNING_MIN => '50',
            AlertSettings::KEY_BOUNCE_PERCENT => '15', AlertSettings::KEY_BOUNCE_WINDOW => '120', AlertSettings::KEY_BOUNCE_MIN_SENDS => '300',
        ]);

        $this->assertSame(['enabled' => true, 'warningPercent' => 7, 'warningMinContacts' => 50, 'bouncePercent' => 15, 'bounceWindow' => 120, 'bounceMinSends' => 300], $current);
    }

    public function testValuesOutsideTheLimitsAreClamped(): void
    {
        $low  = AlertSettings::fromArray([AlertSettings::KEY_WARNING_PERCENT => 0, AlertSettings::KEY_WARNING_MIN => 0, AlertSettings::KEY_BOUNCE_PERCENT => 0, AlertSettings::KEY_BOUNCE_WINDOW => 1, AlertSettings::KEY_BOUNCE_MIN_SENDS => -4]);
        $high = AlertSettings::fromArray([AlertSettings::KEY_WARNING_PERCENT => 50, AlertSettings::KEY_WARNING_MIN => 999999, AlertSettings::KEY_BOUNCE_PERCENT => 500, AlertSettings::KEY_BOUNCE_WINDOW => 99999, AlertSettings::KEY_BOUNCE_MIN_SENDS => 999999]);

        $this->assertSame([1, 1, 1, 5, 1], [$low['warningPercent'], $low['warningMinContacts'], $low['bouncePercent'], $low['bounceWindow'], $low['bounceMinSends']]);
        $this->assertSame([9, 100000, 100, 1440, 100000], [$high['warningPercent'], $high['warningMinContacts'], $high['bouncePercent'], $high['bounceWindow'], $high['bounceMinSends']]);
    }

    public function testATextThatIsNotANumberFallsBackToTheDefault(): void
    {
        $this->assertSame(5, AlertSettings::fromArray([AlertSettings::KEY_WARNING_PERCENT => 'abc'])['warningPercent']);
    }

    public function testCurrentReadsTheFeatureSettingsOfTheIntegration(): void
    {
        $settings = $this->createStub(Integration::class);
        $settings->method('getFeatureSettings')->willReturn([AlertSettings::KEY_ENABLED => 1, AlertSettings::KEY_WARNING_PERCENT => 3]);
        $integration = $this->createStub(N8nDispatchIntegration::class);
        $integration->method('getIntegrationSettings')->willReturn($settings);
        $helper = $this->createMock(IntegrationHelper::class);
        $helper->expects($this->once())->method('getIntegrationObject')->with(N8nDispatchIntegration::NAME)->willReturn($integration);

        $current = (new AlertSettings($helper))->current();

        $this->assertTrue($current['enabled']);
        $this->assertSame(3, $current['warningPercent']);
    }

    public function testWithoutTheIntegrationTheChannelIsOff(): void
    {
        $helper = $this->createStub(IntegrationHelper::class);
        $helper->method('getIntegrationObject')->willReturn(false);

        $this->assertFalse((new AlertSettings($helper))->current()['enabled']);
    }
}
