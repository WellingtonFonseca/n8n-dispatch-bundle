<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service;

use Mautic\PluginBundle\Entity\Integration as IntegrationSettings;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\N8nDispatchBundle\Entity\DispatchTracking;
use MauticPlugin\N8nDispatchBundle\Integration\N8nDispatchIntegration;
use MauticPlugin\N8nDispatchBundle\Service\StatusPollSettings;
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

    /** @var StatusPollSettings&MockObject */
    private StatusPollSettings $settings;

    private StatusPoller $poller;

    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->integrationHelper = $this->createMock(IntegrationHelper::class);
        $this->httpClient        = $this->createMock(HttpClientInterface::class);
        $this->tracker           = $this->createMock(StatusTracker::class);
        $this->settings          = $this->createMock(StatusPollSettings::class);
        $this->settings->method('current')->willReturn(['enabled' => false, 'interval' => 60, 'batch' => 100, 'timeout' => 180, 'maxDuration' => 300]);
        $this->poller            = new StatusPoller(
            $this->integrationHelper,
            $this->httpClient,
            $this->tracker,
            new StatusResponseParser(),
            $this->createMock(LoggerInterface::class),
            $this->settings,
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

    private function row(string $channel, string $refType, string $refValue, string $group, ?int $id = null): DispatchTracking
    {
        $row = DispatchTracking::create($channel, $refType, $refValue, $group, new \DateTimeImmutable('2026-10-02 09:00:00'));

        if (null !== $id) {
            (new \ReflectionProperty($row, 'id'))->setValue($row, $id);
        }

        return $row;
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

        $summary = $this->poller->poll(DispatchTracking::CHANNEL_EMAIL, 100, 7, DispatchTracking::OUTCOME_PENDING, null, $this->now);

        $this->assertSame(0, $summary['requested']);
        $this->assertNull($summary['error']);
    }

    public function testSendsTheItemsWithTheStatusActionAndTheTokenThenAppliesTheResponse(): void
    {
        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/hook', 'webhook_token' => 'secret']);
        $this->tracker->method('pending')->with('email', 'pending', 100, 7, $this->now, 0)->willReturn([
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

        $summary = $this->poller->poll(DispatchTracking::CHANNEL_EMAIL, 100, 7, DispatchTracking::OUTCOME_PENDING, null, $this->now);

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

        $this->poller->poll(DispatchTracking::CHANNEL_HSM, 100, 7, DispatchTracking::OUTCOME_PENDING, null, $this->now);
    }

    public function testStopsWithAnErrorWhenTheIntegrationIsDisabled(): void
    {
        $this->mockIntegration(false, ['webhook_url' => 'https://n8n.example.test/hook']);
        $this->httpClient->expects($this->never())->method('request');
        $this->tracker->expects($this->never())->method('pending');

        $summary = $this->poller->poll(DispatchTracking::CHANNEL_EMAIL, 100, 7, DispatchTracking::OUTCOME_PENDING, null, $this->now);

        $this->assertNotNull($summary['error']);
    }

    public function testStopsWithAnErrorWhenThereIsNoWebhookUrl(): void
    {
        $this->mockIntegration(true, ['webhook_url' => '  ']);
        $this->httpClient->expects($this->never())->method('request');

        $summary = $this->poller->poll(DispatchTracking::CHANNEL_EMAIL, 100, 7, DispatchTracking::OUTCOME_PENDING, null, $this->now);

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

        $this->poller->poll(DispatchTracking::CHANNEL_EMAIL, 100, 7, DispatchTracking::OUTCOME_PENDING, 'http://127.0.0.1:8099/', $this->now);
    }

    public function testAnHttpErrorLeavesEverythingPending(): void
    {
        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/hook']);
        $this->tracker->method('pending')->willReturn([$this->row('email', 'logSendEmailId', '1', 'g')]);
        $this->httpClient->method('request')->willReturn($this->response(500, '{"items":[{"logSendEmailId":1,"outcome":"success"}]}'));
        $this->tracker->expects($this->never())->method('apply');

        $summary = $this->poller->poll(DispatchTracking::CHANNEL_EMAIL, 100, 7, DispatchTracking::OUTCOME_PENDING, null, $this->now);

        $this->assertNotNull($summary['error']);
        $this->assertSame(0, $summary['changed']);
    }

    public function testATransportFailureLeavesEverythingPending(): void
    {
        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/hook']);
        $this->tracker->method('pending')->willReturn([$this->row('email', 'logSendEmailId', '1', 'g')]);
        $this->httpClient->method('request')->willThrowException(new \RuntimeException('connection refused'));
        $this->tracker->expects($this->never())->method('apply');

        $summary = $this->poller->poll(DispatchTracking::CHANNEL_EMAIL, 100, 7, DispatchTracking::OUTCOME_PENDING, null, $this->now);

        $this->assertStringContainsString('connection refused', (string) $summary['error']);
    }

    public function testABodyWithoutAnItemsListLeavesEverythingPending(): void
    {
        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/hook']);
        $this->tracker->method('pending')->willReturn([$this->row('email', 'logSendEmailId', '1', 'g')]);
        $this->httpClient->method('request')->willReturn($this->response(200, '<html>oops</html>'));
        $this->tracker->expects($this->never())->method('apply');

        $summary = $this->poller->poll(DispatchTracking::CHANNEL_EMAIL, 100, 7, DispatchTracking::OUTCOME_PENDING, null, $this->now);

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

        $summary = $this->poller->poll(DispatchTracking::CHANNEL_EMAIL, 100, 7, DispatchTracking::OUTCOME_PENDING, null, $this->now);

        $this->assertSame(1, $summary['invalid']);
        $this->assertSame(1, $summary['changed']);
    }

    /**
     * @param list<int> $ids
     *
     * @return list<DispatchTracking>
     */
    private function page(array $ids): array
    {
        return array_map(fn (int $id): DispatchTracking => $this->row('email', 'logSendEmailId', (string) (4000 + $id), 'g'.$id, $id), $ids);
    }

    public function testKeepsAskingInBatchesUntilTheQueueIsEmpty(): void
    {
        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/hook']);
        $cursors = [];
        $this->tracker->method('pending')->willReturnCallback(function (string $c, string $o, int $limit, int $age, \DateTimeImmutable $now, int $after) use (&$cursors): array {
            $cursors[] = $after;

            return match ($after) {
                0       => $this->page([1, 2]),
                2       => $this->page([3, 4]),
                4       => $this->page([5]),
                default => [],
            };
        });
        $this->httpClient->expects($this->exactly(3))->method('request')->willReturn($this->response(200, '{"items":[]}'));
        $this->tracker->method('apply')->willReturn(['changed' => 1, 'unchanged' => 1, 'unknown' => 0]);

        $summary = $this->poller->poll(DispatchTracking::CHANNEL_EMAIL, 2, 7, DispatchTracking::OUTCOME_PENDING, null, $this->now);

        $this->assertSame([0, 2, 4], $cursors, 'each page starts after the last id of the one before, so nothing is asked twice');
        $this->assertSame(3, $summary['calls']);
        $this->assertSame(5, $summary['requested']);
        $this->assertSame(3, $summary['changed']);
        $this->assertSame(3, $summary['unchanged']);
        $this->assertNull($summary['error']);
    }

    private function pollerWithDelay(int $delayMs, array &$sleeps): StatusPoller
    {
        $settings = $this->createMock(StatusPollSettings::class);
        $settings->method('current')->willReturn(['enabled' => false, 'interval' => 60, 'batch' => 2, 'timeout' => 180, 'delay' => $delayMs, 'maxDuration' => 300]);

        return new StatusPoller($this->integrationHelper, $this->httpClient, $this->tracker, new StatusResponseParser(), $this->createMock(LoggerInterface::class), $settings, function (int $ms) use (&$sleeps): void { $sleeps[] = $ms; });
    }

    public function testItPausesBetweenTwoCallsButNotBeforeTheFirst(): void
    {
        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/hook']);
        $this->tracker->method('pending')->willReturnCallback(fn (string $c, string $o, int $limit, int $age, \DateTimeImmutable $now, int $after): array => match ($after) {
            0       => $this->page([1, 2]),
            2       => $this->page([3, 4]),
            4       => $this->page([5]),
            default => [],
        });
        $this->httpClient->expects($this->exactly(3))->method('request')->willReturn($this->response(200, '{"items":[]}'));
        $this->tracker->method('apply')->willReturn(['changed' => 1, 'unchanged' => 1, 'unknown' => 0]);
        $sleeps = [];

        $this->pollerWithDelay(1500, $sleeps)->poll(DispatchTracking::CHANNEL_EMAIL, 2, 7, DispatchTracking::OUTCOME_PENDING, null, $this->now);

        $this->assertCount(2, $sleeps, 'three calls, two pauses: none before the first');
        foreach ($sleeps as $ms) {
            $this->assertGreaterThan(1400, $ms);
            $this->assertLessThanOrEqual(1500, $ms);
        }
    }

    public function testThePauseAlsoSeparatesTwoChannelsOfTheSamePoller(): void
    {
        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/hook']);
        $this->tracker->method('pending')->willReturnCallback(fn (string $c, string $o, int $limit, int $age, \DateTimeImmutable $now, int $after): array => 0 === $after ? $this->page([1]) : []);
        $this->httpClient->method('request')->willReturn($this->response(200, '{"items":[]}'));
        $this->tracker->method('apply')->willReturn(['changed' => 1, 'unchanged' => 0, 'unknown' => 0]);
        $sleeps = [];
        $poller = $this->pollerWithDelay(1000, $sleeps);

        $poller->poll(DispatchTracking::CHANNEL_EMAIL, 2, 7, DispatchTracking::OUTCOME_PENDING, null, $this->now);
        $poller->poll(DispatchTracking::CHANNEL_SMS, 2, 7, DispatchTracking::OUTCOME_PENDING, null, $this->now);

        $this->assertCount(1, $sleeps, 'the first call of the second channel waits for the last call of the first');
    }

    public function testAZeroPauseNeverSleeps(): void
    {
        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/hook']);
        $this->tracker->method('pending')->willReturnCallback(fn (string $c, string $o, int $limit, int $age, \DateTimeImmutable $now, int $after): array => match ($after) {
            0       => $this->page([1, 2]),
            2       => $this->page([3, 4]),
            default => [],
        });
        $this->httpClient->method('request')->willReturn($this->response(200, '{"items":[]}'));
        $this->tracker->method('apply')->willReturn(['changed' => 1, 'unchanged' => 1, 'unknown' => 0]);
        $sleeps = [];

        $this->pollerWithDelay(0, $sleeps)->poll(DispatchTracking::CHANNEL_EMAIL, 2, 7, DispatchTracking::OUTCOME_PENDING, null, $this->now);

        $this->assertSame([], $sleeps);
    }

    public function testAFullLastPageIsFollowedByOneMoreReadOfTheQueue(): void
    {
        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/hook']);
        $this->tracker->method('pending')->willReturnCallback(fn (string $c, string $o, int $limit, int $age, \DateTimeImmutable $now, int $after): array => 0 === $after ? $this->page([1, 2]) : []);
        $this->httpClient->expects($this->once())->method('request')->willReturn($this->response(200, '{"items":[]}'));
        $this->tracker->method('apply')->willReturn(['changed' => 0, 'unchanged' => 2, 'unknown' => 0]);

        $summary = $this->poller->poll(DispatchTracking::CHANNEL_EMAIL, 2, 7, DispatchTracking::OUTCOME_PENDING, null, $this->now);

        $this->assertSame(1, $summary['calls']);
    }

    public function testAFailureInTheMiddleStopsTheRunButKeepsWhatWasAlreadyApplied(): void
    {
        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/hook']);
        $this->tracker->method('pending')->willReturnCallback(fn (string $c, string $o, int $limit, int $age, \DateTimeImmutable $now, int $after): array => match ($after) {
            0       => $this->page([1, 2]),
            2       => $this->page([3, 4]),
            default => $this->page([5, 6]),
        });
        $this->httpClient->expects($this->exactly(2))->method('request')->willReturnOnConsecutiveCalls(
            $this->response(200, '{"items":[]}'),
            $this->response(500, '{"error":"mirror down"}')
        );
        $this->tracker->expects($this->once())->method('apply')->willReturn(['changed' => 2, 'unchanged' => 0, 'unknown' => 0]);

        $summary = $this->poller->poll(DispatchTracking::CHANNEL_EMAIL, 2, 7, DispatchTracking::OUTCOME_PENDING, null, $this->now);

        $this->assertSame(2, $summary['changed'], 'the first batch stays applied');
        $this->assertSame(1, $summary['calls'], 'only the answered call counts');
        $this->assertNotNull($summary['error']);
    }

    public function testStopsWhenABatchMakesNoProgress(): void
    {
        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/hook']);
        $this->tracker->method('pending')->willReturnCallback(fn (string $c, string $o, int $limit, int $age, \DateTimeImmutable $now, int $after): array => $this->page([$after + 1, $after + 2]));
        // n8n answers nothing usable: no item applied at all
        $this->httpClient->expects($this->once())->method('request')->willReturn($this->response(200, '{"items":[]}'));
        $this->tracker->method('apply')->willReturn(['changed' => 0, 'unchanged' => 0, 'unknown' => 0]);

        $summary = $this->poller->poll(DispatchTracking::CHANNEL_EMAIL, 2, 7, DispatchTracking::OUTCOME_PENDING, null, $this->now);

        $this->assertSame(1, $summary['calls']);
    }

    public function testTheCallCapStopsARunThatWouldNeverEnd(): void
    {
        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/hook']);
        $this->tracker->method('pending')->willReturnCallback(fn (string $c, string $o, int $limit, int $age, \DateTimeImmutable $now, int $after): array => $this->page([$after + 1, $after + 2]));
        $this->httpClient->expects($this->exactly(3))->method('request')->willReturn($this->response(200, '{"items":[]}'));
        $this->tracker->method('apply')->willReturn(['changed' => 0, 'unchanged' => 2, 'unknown' => 0]);

        $summary = $this->poller->poll(DispatchTracking::CHANNEL_EMAIL, 2, 7, DispatchTracking::OUTCOME_PENDING, null, $this->now, 3);

        $this->assertSame(3, $summary['calls']);
    }

    public function testOneCallOnlyWhenTheCapIsOne(): void
    {
        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/hook']);
        $this->tracker->method('pending')->willReturn($this->page([1, 2]));
        $this->httpClient->expects($this->once())->method('request')->willReturn($this->response(200, '{"items":[]}'));
        $this->tracker->method('apply')->willReturn(['changed' => 0, 'unchanged' => 2, 'unknown' => 0]);

        $this->poller->poll(DispatchTracking::CHANNEL_EMAIL, 2, 7, DispatchTracking::OUTCOME_PENDING, null, $this->now, 1);
    }

    public function testEveryCallHasTheConfiguredTimeoutSoAHungN8nCannotHoldTheRunForever(): void
    {
        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/hook']);
        $this->tracker->method('pending')->willReturn([$this->row('email', 'logSendEmailId', '1', 'g', 1)]);
        $this->httpClient->expects($this->once())->method('request')
            ->with('POST', $this->anything(), $this->callback(
                fn (array $options): bool => 180.0 === (float) $options['timeout'] && 300.0 === (float) $options['max_duration']
            ))
            ->willReturn($this->response(200, '{"items":[]}'));
        $this->tracker->method('apply')->willReturn(['changed' => 0, 'unchanged' => 1, 'unknown' => 0]);

        $this->poller->poll(DispatchTracking::CHANNEL_EMAIL, 100, 7, DispatchTracking::OUTCOME_PENDING, null, $this->now);
    }

    public function testTheTimeoutFollowsTheSettingsScreen(): void
    {
        $settings = $this->createMock(StatusPollSettings::class);
        $settings->method('current')->willReturn(['enabled' => false, 'interval' => 60, 'batch' => 100, 'timeout' => 45, 'maxDuration' => 90]);
        $poller = new StatusPoller($this->integrationHelper, $this->httpClient, $this->tracker, new StatusResponseParser(), $this->createMock(LoggerInterface::class), $settings);

        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/hook']);
        $this->tracker->method('pending')->willReturn([$this->row('email', 'logSendEmailId', '1', 'g', 1)]);
        $this->httpClient->expects($this->once())->method('request')
            ->with('POST', $this->anything(), $this->callback(
                fn (array $options): bool => 45.0 === (float) $options['timeout'] && 90.0 === (float) $options['max_duration']
            ))
            ->willReturn($this->response(200, '{"items":[]}'));
        $this->tracker->method('apply')->willReturn(['changed' => 0, 'unchanged' => 1, 'unknown' => 0]);

        $poller->poll(DispatchTracking::CHANNEL_EMAIL, 100, 7, DispatchTracking::OUTCOME_PENDING, null, $this->now);
    }

    public function testATimedOutCallEndsTheRunWithAnErrorAndKeepsTheEarlierBatches(): void
    {
        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/hook']);
        $this->tracker->method('pending')->willReturnCallback(fn (string $c, string $o, int $limit, int $age, \DateTimeImmutable $now, int $after): array => $this->page([$after + 1, $after + 2]));
        $this->httpClient->expects($this->exactly(2))->method('request')->willReturnCallback(function (): ResponseInterface {
            static $call = 0;

            if (1 === ++$call) {
                return $this->response(200, '{"items":[]}');
            }

            throw new \Symfony\Component\HttpClient\Exception\TimeoutException('Idle timeout reached for "https://n8n.example.test/hook".');
        });
        $this->tracker->method('apply')->willReturn(['changed' => 2, 'unchanged' => 0, 'unknown' => 0]);

        $summary = $this->poller->poll(DispatchTracking::CHANNEL_EMAIL, 2, 7, DispatchTracking::OUTCOME_PENDING, null, $this->now);

        $this->assertSame(1, $summary['calls']);
        $this->assertSame(2, $summary['changed']);
        $this->assertStringContainsString('timeout', strtolower((string) $summary['error']));
    }

    private function pollWith(string $channel = DispatchTracking::CHANNEL_EMAIL, ?string $override = null): array
    {
        return $this->poller->poll($channel, 100, 7, DispatchTracking::OUTCOME_PENDING, $override, $this->now);
    }

    public function testADisabledIntegrationReturnsTheWholeEmptySummaryWithItsError(): void
    {
        $this->mockIntegration(false, ['webhook_url' => 'https://n8n.example.test/hook']);
        $this->tracker->expects($this->never())->method('pending');

        $this->assertSame(
            ['requested' => 0, 'changed' => 0, 'unchanged' => 0, 'unknown' => 0, 'invalid' => 0, 'calls' => 0, 'error' => 'the N8N Dispatch integration is not configured/enabled.'],
            $this->pollWith()
        );
    }

    public function testAMissingWebhookUrlReturnsItsErrorWithoutAskingTheQueue(): void
    {
        $this->mockIntegration(true, ['webhook_url' => '  ']);
        $this->tracker->expects($this->never())->method('pending');

        $this->assertSame('webhook_url is not configured.', $this->pollWith()['error']);
    }

    public function testTheOverrideTheUrlAndTheTokenAreTrimmedAndTheHeadersAreExact(): void
    {
        $this->mockIntegration(true, ['webhook_url' => ' https://n8n.example.test/hook ', 'webhook_token' => '  tok ']);
        $this->tracker->method('pending')->willReturn([$this->row('email', 'logSendEmailId', '1', 'g')]);
        $this->tracker->method('apply')->willReturn(['changed' => 0, 'unchanged' => 0, 'unknown' => 0]);
        $calls = [];
        $this->httpClient->method('request')->willReturnCallback(function (string $m, string $url, array $options) use (&$calls): ResponseInterface {
            $calls[] = [$url, $options['headers']];

            return $this->response(200, '{"items":[]}');
        });

        $this->pollWith();
        $this->pollWith(DispatchTracking::CHANNEL_EMAIL, "  http://127.0.0.1:8099/\n");

        $headers = ['Content-Type' => 'application/json', 'X-N8n-Dispatch-Action' => 'email.status', 'X-N8n-Dispatch-Token' => 'tok'];
        $this->assertSame([['https://n8n.example.test/hook', $headers], ['http://127.0.0.1:8099/', $headers]], $calls);
    }

    public function testATokenOfOnlySpacesSendsNoTokenHeader(): void
    {
        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/hook', 'webhook_token' => '   ']);
        $this->tracker->method('pending')->willReturn([$this->row('email', 'logSendEmailId', '1', 'g')]);
        $this->tracker->method('apply')->willReturn(['changed' => 0, 'unchanged' => 0, 'unknown' => 0]);
        $this->httpClient->expects($this->once())->method('request')->with('POST', $this->anything(), $this->callback(fn (array $o): bool => !isset($o['headers']['X-N8n-Dispatch-Token'])))->willReturn($this->response(200, '{"items":[]}'));

        $this->pollWith();
    }

    public function testAFailedCallIsLoggedWithItsChannelAndReason(): void
    {
        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/hook']);
        $this->tracker->method('pending')->willReturn([$this->row('sms', 'logSendSmsId', '1', 'g')]);
        $this->httpClient->method('request')->willThrowException(new \RuntimeException('boom'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with('N8nDispatch: sms.status call failed: boom');
        $poller = new StatusPoller($this->integrationHelper, $this->httpClient, $this->tracker, new StatusResponseParser(), $logger, $this->settings);

        $summary = $poller->poll(DispatchTracking::CHANNEL_SMS, 100, 7, DispatchTracking::OUTCOME_PENDING, null, $this->now);

        $this->assertSame('boom', $summary['error']);
    }

    public function testHttp300IsRefusedWithItsStatus(): void
    {
        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/hook']);
        $this->tracker->method('pending')->willReturn([$this->row('email', 'logSendEmailId', '1', 'g')]);
        $this->httpClient->method('request')->willReturn($this->response(300, '{"items":[]}'));

        $this->assertSame('webhook returned HTTP 300.', $this->pollWith()['error']);
    }

    public function testHttp299IsAccepted(): void
    {
        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/hook']);
        $this->tracker->method('pending')->willReturn([$this->row('email', 'logSendEmailId', '1', 'g')]);
        $this->tracker->method('apply')->willReturn(['changed' => 0, 'unchanged' => 0, 'unknown' => 0]);
        $this->httpClient->method('request')->willReturn($this->response(299, '{"items":[]}'));

        $this->assertNull($this->pollWith()['error']);
    }

    public function testInvalidItemsAreSummedAcrossCalls(): void
    {
        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/hook']);
        $this->tracker->method('pending')->willReturnCallback(fn (string $c, string $o, int $limit, int $age, \DateTimeImmutable $now, int $after): array => match ($after) {
            0       => $this->page([1, 2]),
            2       => $this->page([3, 4]),
            default => [],
        });
        $this->httpClient->method('request')->willReturn($this->response(200, '{"items":[{"logSendEmailId":1},{"outcome":"success"}]}'));
        $this->tracker->method('apply')->willReturn(['changed' => 1, 'unchanged' => 0, 'unknown' => 0]);

        $summary = $this->poller->poll(DispatchTracking::CHANNEL_EMAIL, 2, 7, DispatchTracking::OUTCOME_PENDING, null, $this->now);

        $this->assertSame(2, $summary['calls']);
        $this->assertSame(4, $summary['invalid'], 'two invalid items on each of the two calls');
    }

    public function testWithoutADelayInTheSettingsThereIsNoPause(): void
    {
        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/hook']);
        $this->tracker->method('pending')->willReturnCallback(fn (string $c, string $o, int $limit, int $age, \DateTimeImmutable $now, int $after): array => match ($after) {
            0       => $this->page([1, 2]),
            2       => $this->page([3, 4]),
            default => [],
        });
        $this->httpClient->method('request')->willReturn($this->response(200, '{"items":[]}'));
        $this->tracker->method('apply')->willReturn(['changed' => 1, 'unchanged' => 1, 'unknown' => 0]);
        $settings = $this->createMock(StatusPollSettings::class);
        $settings->method('current')->willReturn(['timeout' => 180, 'maxDuration' => 300]);
        $sleeps = [];
        $poller = new StatusPoller($this->integrationHelper, $this->httpClient, $this->tracker, new StatusResponseParser(), $this->createMock(LoggerInterface::class), $settings, function (int $ms) use (&$sleeps): void { $sleeps[] = $ms; });

        $poller->poll(DispatchTracking::CHANNEL_EMAIL, 2, 7, DispatchTracking::OUTCOME_PENDING, null, $this->now);

        $this->assertSame([], $sleeps);
    }

    public function testOnlyNumericMirrorIdsGoOutAsNumbers(): void
    {
        $this->mockIntegration(true, ['webhook_url' => 'https://n8n.example.test/hook']);
        $this->tracker->method('pending')->willReturn([
            $this->row('hsm', 'logSendHsmId', '123', 'g1'),
            $this->row('hsm', 'logSendHsmUuid', '456', 'g1'),
            $this->row('hsm', 'logSendHsmId', 'abc', 'g2'),
        ]);
        $this->tracker->method('apply')->willReturn(['changed' => 0, 'unchanged' => 0, 'unknown' => 0]);
        $this->httpClient->expects($this->once())->method('request')->with('POST', $this->anything(), $this->callback(
            fn (array $o): bool => [['logSendHsmId' => 123, 'logSendHsmUuid' => '456'], ['logSendHsmId' => 'abc']] === $o['json']['items']
        ))->willReturn($this->response(200, '{"items":[]}'));

        $this->pollWith(DispatchTracking::CHANNEL_HSM);
    }
}
