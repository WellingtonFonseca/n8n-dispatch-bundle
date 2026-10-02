<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service;

use Mautic\PluginBundle\Entity\Integration as IntegrationSettings;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\N8nDispatchBundle\Entity\DispatchTracking;
use MauticPlugin\N8nDispatchBundle\Integration\N8nDispatchIntegration;
use MauticPlugin\N8nDispatchBundle\Service\StatusPoller;
use MauticPlugin\N8nDispatchBundle\Service\StatusResponseParser;
use MauticPlugin\N8nDispatchBundle\Service\StatusTracker;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class StatusPollerTest extends TestCase
{
    /** @var IntegrationHelper&MockObject */
    private IntegrationHelper $integrationHelper;

    /** @var HttpClientInterface&MockObject */
    private HttpClientInterface $httpClient;

    /** @var StatusTracker&MockObject */
    private StatusTracker $tracker;

    private StatusPoller $poller;

    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->integrationHelper = $this->createMock(IntegrationHelper::class);
        $this->httpClient        = $this->createMock(HttpClientInterface::class);
        $this->tracker           = $this->createMock(StatusTracker::class);
        $this->poller            = new StatusPoller(
            $this->integrationHelper,
            $this->httpClient,
            $this->tracker,
            new StatusResponseParser(),
            $this->createMock(LoggerInterface::class),
        );
        $this->now = new \DateTimeImmutable('2026-10-02 10:00:00');
    }

    private function mockIntegration(bool $published, array $keys): void
    {
        $settings = $this->createMock(IntegrationSettings::class);
        $settings->method('isPublished')->willReturn($published);
        $integration = $this->createMock(N8nDispatchIntegration::class);
        $integration->method('getIntegrationSettings')->willReturn($settings);
        $integration->method('getKeys')->willReturn($keys);
        $this->integrationHelper->method('getIntegrationObject')->willReturn($integration);
    }

    private function row(string $channel, string $refType, string $refValue, string $group): DispatchTracking
    {
        return DispatchTracking::create($channel, $refType, $refValue, $group, new \DateTimeImmutable('2026-10-02 09:00:00'));
    }

    private function response(int $status, string $body): ResponseInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getContent')->with(false)->willReturn($body);

        return $response;
    }

    public function testDoesNotCallTheWebhookWhenNothingIsPending(): void
    {
        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/hook']);
        $this->tracker->method('pending')->willReturn([]);
        $this->httpClient->expects($this->never())->method('request');

        $summary = $this->poller->poll(DispatchTracking::CHANNEL_EMAIL, 200, 7, DispatchTracking::OUTCOME_PENDING, null, $this->now);

        $this->assertSame(0, $summary['requested']);
        $this->assertNull($summary['error']);
    }

    public function testSendsTheItemsWithTheStatusActionAndTheTokenThenAppliesTheResponse(): void
    {
        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/hook', 'webhook_token' => 'secret']);
        $this->tracker->method('pending')->with('email', 'pending', 200, 7, $this->now)->willReturn([
            $this->row('email', 'logSendEmailId', '4298591', 'g1'),
            $this->row('email', 'logSendEmailId', '4298592', 'g2'),
        ]);

        $this->httpClient->expects($this->once())->method('request')
            ->with('POST', 'https://n8n.example.test/hook', $this->callback(function (array $options): bool {
                return 'email.status' === $options['headers']['X-N8n-Dispatch-Action']
                    && 'secret' === $options['headers']['X-N8n-Dispatch-Token']
                    && ['items' => [['logSendEmailId' => 4298591], ['logSendEmailId' => 4298592]]] === $options['json'];
            }))
            ->willReturn($this->response(200, json_encode(['items' => [
                ['logSendEmailId' => 4298591, 'outcome' => 'success'],
                ['logSendEmailId' => 4298592, 'outcome' => 'pending'],
            ]])));

        $this->tracker->expects($this->once())->method('apply')
            ->with($this->callback(fn (array $items): bool => 2 === count($items)), $this->now)
            ->willReturn(['changed' => 1, 'unchanged' => 1, 'unknown' => 0]);

        $summary = $this->poller->poll(DispatchTracking::CHANNEL_EMAIL, 200, 7, DispatchTracking::OUTCOME_PENDING, null, $this->now);

        $this->assertSame(2, $summary['requested']);
        $this->assertSame(1, $summary['changed']);
        $this->assertSame(1, $summary['unchanged']);
        $this->assertNull($summary['error']);
    }

    public function testHsmRowsOfTheSameDispatchGoInOneItemWithBothIds(): void
    {
        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/hook']);
        $this->tracker->method('pending')->willReturn([
            $this->row('hsm', 'logSendHsmId', '4298591', 'g1'),
            $this->row('hsm', 'logSendHsmUuid', '5c48a721-8cb1-43c7-ac46-048ce65b4233', 'g1'),
            $this->row('hsm', 'logSendHsmId', '4298600', 'g2'),
        ]);

        $this->httpClient->expects($this->once())->method('request')
            ->with('POST', $this->anything(), $this->callback(function (array $options): bool {
                return 'hsm.status' === $options['headers']['X-N8n-Dispatch-Action']
                    && [
                        'items' => [
                            ['logSendHsmId' => 4298591, 'logSendHsmUuid' => '5c48a721-8cb1-43c7-ac46-048ce65b4233'],
                            ['logSendHsmId' => 4298600],
                        ],
                    ] === $options['json'];
            }))
            ->willReturn($this->response(200, '{"items":[]}'));
        $this->tracker->method('apply')->willReturn(['changed' => 0, 'unchanged' => 0, 'unknown' => 0]);

        $this->poller->poll(DispatchTracking::CHANNEL_HSM, 200, 7, DispatchTracking::OUTCOME_PENDING, null, $this->now);
    }

    public function testStopsWithAnErrorWhenTheIntegrationIsDisabled(): void
    {
        $this->mockIntegration(false, ['webhook_url' => 'https://n8n.example.test/hook']);
        $this->httpClient->expects($this->never())->method('request');
        $this->tracker->expects($this->never())->method('pending');

        $summary = $this->poller->poll(DispatchTracking::CHANNEL_EMAIL, 200, 7, DispatchTracking::OUTCOME_PENDING, null, $this->now);

        $this->assertNotNull($summary['error']);
    }

    public function testStopsWithAnErrorWhenThereIsNoWebhookUrl(): void
    {
        $this->mockIntegration(true, ['webhook_url' => '  ']);
        $this->httpClient->expects($this->never())->method('request');

        $summary = $this->poller->poll(DispatchTracking::CHANNEL_EMAIL, 200, 7, DispatchTracking::OUTCOME_PENDING, null, $this->now);

        $this->assertNotNull($summary['error']);
    }

    public function testTheWebhookOverrideReplacesTheConfiguredUrl(): void
    {
        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/hook']);
        $this->tracker->method('pending')->willReturn([$this->row('email', 'logSendEmailId', '1', 'g')]);
        $this->httpClient->expects($this->once())->method('request')
            ->with('POST', 'http://127.0.0.1:8099/', $this->anything())
            ->willReturn($this->response(200, '{"items":[]}'));
        $this->tracker->method('apply')->willReturn(['changed' => 0, 'unchanged' => 0, 'unknown' => 0]);

        $this->poller->poll(DispatchTracking::CHANNEL_EMAIL, 200, 7, DispatchTracking::OUTCOME_PENDING, 'http://127.0.0.1:8099/', $this->now);
    }

    public function testAnHttpErrorLeavesEverythingPending(): void
    {
        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/hook']);
        $this->tracker->method('pending')->willReturn([$this->row('email', 'logSendEmailId', '1', 'g')]);
        $this->httpClient->method('request')->willReturn($this->response(500, '{"items":[{"logSendEmailId":1,"outcome":"success"}]}'));
        $this->tracker->expects($this->never())->method('apply');

        $summary = $this->poller->poll(DispatchTracking::CHANNEL_EMAIL, 200, 7, DispatchTracking::OUTCOME_PENDING, null, $this->now);

        $this->assertNotNull($summary['error']);
        $this->assertSame(0, $summary['changed']);
    }

    public function testATransportFailureLeavesEverythingPending(): void
    {
        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/hook']);
        $this->tracker->method('pending')->willReturn([$this->row('email', 'logSendEmailId', '1', 'g')]);
        $this->httpClient->method('request')->willThrowException(new \RuntimeException('connection refused'));
        $this->tracker->expects($this->never())->method('apply');

        $summary = $this->poller->poll(DispatchTracking::CHANNEL_EMAIL, 200, 7, DispatchTracking::OUTCOME_PENDING, null, $this->now);

        $this->assertStringContainsString('connection refused', (string) $summary['error']);
    }

    public function testABodyWithoutAnItemsListLeavesEverythingPending(): void
    {
        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/hook']);
        $this->tracker->method('pending')->willReturn([$this->row('email', 'logSendEmailId', '1', 'g')]);
        $this->httpClient->method('request')->willReturn($this->response(200, '<html>oops</html>'));
        $this->tracker->expects($this->never())->method('apply');

        $summary = $this->poller->poll(DispatchTracking::CHANNEL_EMAIL, 200, 7, DispatchTracking::OUTCOME_PENDING, null, $this->now);

        $this->assertNotNull($summary['error']);
    }

    public function testInvalidItemsAreCountedButDoNotStopTheOthers(): void
    {
        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/hook']);
        $this->tracker->method('pending')->willReturn([$this->row('email', 'logSendEmailId', '1', 'g')]);
        $this->httpClient->method('request')->willReturn($this->response(200, json_encode(['items' => [
            ['logSendEmailId' => 1, 'outcome' => 'bogus'],
            ['logSendEmailId' => 2, 'outcome' => 'success'],
        ]])));
        $this->tracker->expects($this->once())->method('apply')
            ->with($this->callback(fn (array $items): bool => 1 === count($items)), $this->now)
            ->willReturn(['changed' => 1, 'unchanged' => 0, 'unknown' => 0]);

        $summary = $this->poller->poll(DispatchTracking::CHANNEL_EMAIL, 200, 7, DispatchTracking::OUTCOME_PENDING, null, $this->now);

        $this->assertSame(1, $summary['invalid']);
        $this->assertSame(1, $summary['changed']);
    }
}
