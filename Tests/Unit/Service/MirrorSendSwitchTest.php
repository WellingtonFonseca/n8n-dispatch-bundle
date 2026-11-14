<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service;

use Mautic\PluginBundle\Entity\Integration;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\N8nDispatchBundle\Integration\N8nDispatchIntegration;
use MauticPlugin\N8nDispatchBundle\Service\FeatureSettings;
use MauticPlugin\N8nDispatchBundle\Service\MirrorResyncSettings;
use MauticPlugin\N8nDispatchBundle\Service\MirrorSendSwitch;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('area:mirror')]
#[Group('risk:FLUXO')]
class MirrorSendSwitchTest extends TestCase
{
    public function testItIsOnWhenTheKeyIsMissing(): void
    {
        $this->assertTrue(MirrorSendSwitch::fromArray([]));
        $this->assertTrue(MirrorSendSwitch::fromArray([MirrorSendSwitch::KEY_ENABLED => null]));
    }

    /**
     * @dataProvider stored
     */
    public function testItReadsTheStoredValue(mixed $stored, bool $expected): void
    {
        $this->assertSame($expected, MirrorSendSwitch::fromArray([MirrorSendSwitch::KEY_ENABLED => $stored]));
    }

    /**
     * @return array<string, array{mixed, bool}>
     */
    public static function stored(): array
    {
        return ['1' => [1, true], 'text 1' => ['1', true], '0' => [0, false], 'text 0' => ['0', false], 'false' => [false, false], 'empty' => ['', false]];
    }

    public function testIsOnReadsTheFeaturesTab(): void
    {
        $this->assertFalse((new MirrorSendSwitch(new FeatureSettings($this->helper([MirrorSendSwitch::KEY_ENABLED => 0]))))->isOn());
        $this->assertTrue((new MirrorSendSwitch(new FeatureSettings($this->helper([]))))->isOn());
    }

    public function testTheRetiredKeyOfTheJobHasNoEffect(): void
    {
        $this->assertTrue(MirrorSendSwitch::fromArray(['mirror_resync_enabled' => 0]));
        $this->assertArrayNotHasKey('enabled', MirrorResyncSettings::fromArray(['mirror_resync_enabled' => 1]));
    }

    /**
     * @param array<string, mixed> $stored
     */
    private function helper(array $stored): IntegrationHelper
    {
        $settings = $this->createStub(Integration::class);
        $settings->method('getFeatureSettings')->willReturn($stored);
        $integration = $this->createStub(N8nDispatchIntegration::class);
        $integration->method('getIntegrationSettings')->willReturn($settings);
        $helper = $this->createStub(IntegrationHelper::class);
        $helper->method('getIntegrationObject')->willReturn($integration);

        return $helper;
    }
}
