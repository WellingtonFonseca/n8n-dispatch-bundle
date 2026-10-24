<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service;

use Mautic\PluginBundle\Entity\Integration;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\N8nDispatchBundle\Integration\N8nDispatchIntegration;
use MauticPlugin\N8nDispatchBundle\Service\DispatchFailureReasons;
use MauticPlugin\N8nDispatchBundle\Service\WebhookSettings;
use PHPUnit\Framework\TestCase;

class WebhookSettingsTest extends TestCase
{
    /**
     * @param array<string, string> $keys
     */
    private function helper(bool $published, array $keys): IntegrationHelper
    {
        $settings = $this->createStub(Integration::class);
        $settings->method('isPublished')->willReturn($published);

        $integration = $this->createStub(N8nDispatchIntegration::class);
        $integration->method('getIntegrationSettings')->willReturn($settings);
        $integration->method('getKeys')->willReturn($keys);

        $helper = $this->createStub(IntegrationHelper::class);
        $helper->method('getIntegrationObject')->willReturn($integration);

        return $helper;
    }

    public function testTheUrlAndTheHeadersWithTheTokenAreReturned(): void
    {
        $webhook = WebhookSettings::forAction($this->helper(true, ['webhook_url' => ' https://n8n.test/hook ', 'webhook_token' => ' secret ']), 'sms.send');

        $this->assertSame([
            'url'     => 'https://n8n.test/hook',
            'headers' => ['Content-Type' => 'application/json', 'X-N8n-Dispatch-Action' => 'sms.send', 'X-N8n-Dispatch-Token' => 'secret'],
        ], $webhook);
    }

    public function testWithoutATokenThereIsNoTokenHeader(): void
    {
        $webhook = WebhookSettings::forAction($this->helper(true, ['webhook_url' => 'https://n8n.test/hook', 'webhook_token' => '  ']), 'email.send');

        $this->assertSame(['Content-Type' => 'application/json', 'X-N8n-Dispatch-Action' => 'email.send'], $webhook['headers']);
    }

    public function testADisabledIntegrationIsReportedByItsFailureKey(): void
    {
        $this->assertSame(DispatchFailureReasons::INTEGRATION_DISABLED, WebhookSettings::forAction($this->helper(false, ['webhook_url' => 'https://n8n.test/hook']), 'sms.send'));
    }

    public function testAMissingIntegrationIsReportedAsDisabled(): void
    {
        $helper = $this->createStub(IntegrationHelper::class);
        $helper->method('getIntegrationObject')->willReturn(false);

        $this->assertSame(DispatchFailureReasons::INTEGRATION_DISABLED, WebhookSettings::forAction($helper, 'sms.send'));
    }

    public function testAnEmptyUrlIsReportedByItsFailureKey(): void
    {
        $this->assertSame(DispatchFailureReasons::WEBHOOK_MISSING, WebhookSettings::forAction($this->helper(true, ['webhook_url' => '  ']), 'sms.send'));
    }
}
