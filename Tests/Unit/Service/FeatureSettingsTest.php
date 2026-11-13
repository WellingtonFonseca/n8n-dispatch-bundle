<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service;

use Mautic\PluginBundle\Entity\Integration;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\N8nDispatchBundle\Integration\N8nDispatchIntegration;
use MauticPlugin\N8nDispatchBundle\Service\FeatureSettings;
use PHPUnit\Framework\TestCase;

class FeatureSettingsTest extends TestCase
{
    public function testItReturnsWhatTheFeaturesTabStored(): void
    {
        $this->assertSame(['a' => 1], (new FeatureSettings($this->helper(['a' => 1])))->all());
    }

    public function testItReturnsAnEmptyArrayWhenNothingIsStored(): void
    {
        $this->assertSame([], (new FeatureSettings($this->helper(null)))->all());
        $this->assertSame([], (new FeatureSettings($this->helper('broken')))->all());
    }

    public function testItReturnsAnEmptyArrayWithoutAnIntegration(): void
    {
        $helper = $this->createStub(IntegrationHelper::class);
        $helper->method('getIntegrationObject')->willReturn(false);

        $this->assertSame([], (new FeatureSettings($helper))->all());
    }

    private function helper(mixed $stored): IntegrationHelper
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
