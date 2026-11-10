<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service\Alert;

use Mautic\PluginBundle\Entity\Integration;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\N8nDispatchBundle\Integration\N8nDispatchIntegration;
use MauticPlugin\N8nDispatchBundle\Service\Alert\AlertSender;
use MauticPlugin\N8nDispatchBundle\Service\Alert\AlertSettings;
use MauticPlugin\N8nDispatchBundle\Service\Alert\AlertThrottle;
use MauticPlugin\N8nDispatchBundle\Service\DispatchFailureReasons;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class AlertSenderTest extends TestCase
{
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];

    /** @var AlertThrottle&MockObject */
    private AlertThrottle $throttle;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->throttle = $this->createMock(AlertThrottle::class);
        $this->logger   = $this->createMock(LoggerInterface::class);
    }

    /**
     * @param array<string, string> $keys
     */
    private function sender(bool $enabled = true, int $status = 200, array $keys = ['webhook_url' => 'https://n8n.test/hook', 'webhook_token' => 'tok'], bool $published = true, bool $throws = false, string $instance = ''): AlertSender
    {
        $integrationSettings = $this->createStub(Integration::class);
        $integrationSettings->method('isPublished')->willReturn($published);
        $integration = $this->createStub(N8nDispatchIntegration::class);
        $integration->method('getIntegrationSettings')->willReturn($integrationSettings);
        $integration->method('getKeys')->willReturn($keys);
        $helper = $this->createStub(IntegrationHelper::class);
        $helper->method('getIntegrationObject')->willReturn($integration);

        $settings = $this->createStub(AlertSettings::class);
        $settings->method('current')->willReturn(AlertSettings::fromArray([AlertSettings::KEY_ENABLED => $enabled ? 1 : 0, AlertSettings::KEY_INSTANCE_NAME => $instance]));

        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($status, $throws): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            if ($throws) {
                throw new \RuntimeException('connection refused');
            }

            return new MockResponse('', ['http_code' => $status]);
        });

        return new AlertSender($helper, $settings, $this->throttle, $client, $this->logger);
    }

    public function testTheAlertGoesToTheWebhookWithItsOwnActionHeaderAndThePayload(): void
    {
        $this->assertTrue($this->sender()->send('campaign.unpublished', ['campaign' => ['id' => 9]]));

        $this->assertCount(1, $this->requests);
        $this->assertSame('POST', $this->requests[0]['method']);
        $this->assertSame('https://n8n.test/hook', $this->requests[0]['url']);
        $headers = implode("\n", $this->requests[0]['options']['headers']);
        $this->assertStringContainsString('X-N8n-Dispatch-Action: alert.campaign.unpublished', $headers);
        $this->assertStringContainsString('X-N8n-Dispatch-Token: tok', $headers);
        $body = json_decode($this->requests[0]['options']['body'], true);
        $this->assertSame('campaign.unpublished', $body['alert']);
        $this->assertSame(['campaign' => ['id' => 9]], $body['data']);
        $this->assertNotFalse(\DateTimeImmutable::createFromFormat(\DATE_ATOM, $body['occurred_at']));
        $this->assertSame(10.0, (float) $this->requests[0]['options']['timeout']);
    }

    public function testTheAlertCarriesTheNameOfTheInstanceBesideTheType(): void
    {
        $this->sender(instance: 'Mautic Compras')->send('status_poll.failed', ['run_id' => 3]);

        $body = json_decode($this->requests[0]['options']['body'], true);
        $this->assertSame('Mautic Compras', $body['instance']);
        $this->assertSame('status_poll.failed', $body['alert']);
    }

    public function testWithoutAnInstanceNameTheFieldGoesOutEmpty(): void
    {
        $this->sender()->send('status_poll.failed', []);

        $this->assertSame('', json_decode($this->requests[0]['options']['body'], true)['instance']);
    }

    public function testWithTheChannelOffNothingIsSentAndNothingIsClaimed(): void
    {
        $this->throttle->expects($this->never())->method('claim');

        $this->assertFalse($this->sender(false)->send('campaign.unpublished', [], 'k', 60));
        $this->assertSame([], $this->requests);
    }

    public function testACooldownKeyAlreadyTakenSendsNothing(): void
    {
        $this->throttle->expects($this->once())->method('claim')->with('warning:7', 86400)->willReturn(false);

        $this->assertFalse($this->sender()->send('campaign.failure_warning', [], 'warning:7', 86400));
        $this->assertSame([], $this->requests);
    }

    public function testAFreeCooldownKeyIsClaimedAndKeptAfterASuccessfulSend(): void
    {
        $this->throttle->expects($this->once())->method('claim')->with('warning:7', 86400)->willReturn(true);
        $this->throttle->expects($this->never())->method('release');

        $this->assertTrue($this->sender()->send('campaign.failure_warning', [], 'warning:7', 86400));
    }

    public function testAnAlertWithoutAKeyIsNeverThrottled(): void
    {
        $this->throttle->expects($this->never())->method('claim');
        $this->throttle->expects($this->never())->method('release');

        $this->assertTrue($this->sender()->send('dispatch.resend_failed', []));
    }

    public function testARefusedAlertIsLoggedAndItsKeyIsFreedForTheNextCheck(): void
    {
        $this->throttle->method('claim')->willReturn(true);
        $this->throttle->expects($this->once())->method('release')->with('warning:7');
        $this->logger->expects($this->once())->method('error')->with('N8nDispatch: alert alert.campaign.failure_warning refused by the webhook: HTTP 422');

        $this->assertFalse($this->sender(true, 422)->send('campaign.failure_warning', [], 'warning:7', 60));
    }

    public function testAStatusOf299IsDeliveredAnd300IsNot(): void
    {
        $this->assertTrue($this->sender(true, 299)->send('a', []));
        $this->assertFalse($this->sender(true, 300)->send('a', []));
    }

    public function testAFailedCallIsLoggedWithItsReasonAndNeverThrown(): void
    {
        $this->logger->expects($this->once())->method('error')->with('N8nDispatch: alert alert.campaign.unpublished failed: connection refused');

        $this->assertFalse($this->sender(true, 200, ['webhook_url' => 'https://n8n.test/hook'], true, true)->send('campaign.unpublished', []));
    }

    public function testAMissingWebhookIsLoggedAndNothingIsSent(): void
    {
        $this->logger->expects($this->once())->method('warning')->with('N8nDispatch: alert alert.campaign.unpublished not sent: '.DispatchFailureReasons::WEBHOOK_MISSING);
        $this->logger->expects($this->never())->method('error');

        $this->assertFalse($this->sender(true, 200, ['webhook_url' => ''])->send('campaign.unpublished', []));
        $this->assertSame([], $this->requests);
    }

    public function testADisabledIntegrationSendsNothing(): void
    {
        $this->assertFalse($this->sender(true, 200, ['webhook_url' => 'https://n8n.test/hook'], false)->send('campaign.unpublished', []));
        $this->assertSame([], $this->requests);
    }

    public function testAFailingCacheNeverBreaksTheCaller(): void
    {
        $this->throttle->method('claim')->willThrowException(new \RuntimeException('cache dir is not writable'));
        $this->logger->expects($this->once())->method('error')->with('N8nDispatch: alert alert.campaign.failure_warning not sent: cache dir is not writable');

        $this->assertFalse($this->sender()->send('campaign.failure_warning', [], 'warning:7', 60));
        $this->assertSame([], $this->requests);
    }

    public function testFailingSettingsNeverBreakTheCaller(): void
    {
        $settings = $this->createStub(AlertSettings::class);
        $settings->method('current')->willThrowException(new \RuntimeException('db gone'));
        $helper = $this->createStub(IntegrationHelper::class);
        $this->logger->expects($this->once())->method('error')->with('N8nDispatch: alert alert.campaign.unpublished not sent: db gone');

        $this->assertFalse((new AlertSender($helper, $settings, $this->throttle, new MockHttpClient(), $this->logger))->send('campaign.unpublished', []));
    }
}
