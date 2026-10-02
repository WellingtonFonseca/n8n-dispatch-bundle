<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service;

use MauticPlugin\N8nDispatchBundle\Service\StatusPoller;
use MauticPlugin\N8nDispatchBundle\Service\StatusPollSettings;
use MauticPlugin\N8nDispatchBundle\Service\StatusPollNow;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class StatusPollNowTest extends TestCase
{
    /** @var StatusPoller&MockObject */
    private StatusPoller $poller;

    /** @var StatusPollSettings&MockObject */
    private StatusPollSettings $settings;

    private StatusPollNow $now;

    protected function setUp(): void
    {
        $this->poller = $this->createMock(StatusPoller::class);
        $this->settings = $this->createMock(StatusPollSettings::class);
        $this->settings->method('current')->willReturn(['enabled' => false, 'interval' => 60, 'batch' => 100, 'timeout' => 180, 'maxDuration' => 300]);
        $this->now      = new StatusPollNow($this->poller, $this->settings);
    }

    /**
     * @return array<string, int|string|null>
     */
    private function summary(int $requested = 0, ?string $error = null): array
    {
        return ['requested' => $requested, 'changed' => 0, 'unchanged' => $requested, 'unknown' => 0, 'invalid' => 0, 'calls' => $requested > 0 ? 1 : 0, 'error' => $error];
    }

    public function testAsksTheThreeChannelsOnceEachInABatchOfOneHundred(): void
    {
        $asked = [];
        $this->poller->method('poll')->willReturnCallback(function (string $channel, int $limit, int $maxAge, string $outcome, ?string $override, \DateTimeImmutable $now, int $maxCalls) use (&$asked): array {
            $asked[] = [$channel, $limit, $maxAge, $outcome, $override, $maxCalls];

            return $this->summary();
        });

        $result = $this->now->run(new \DateTimeImmutable('2026-10-02 10:00:00'));

        $this->assertSame([
            ['email', 100, 7, 'pending', null, 1],
            ['sms', 100, 7, 'pending', null, 1],
            ['hsm', 100, 7, 'pending', null, 1],
        ], $asked);
        $this->assertSame(['email', 'sms', 'hsm'], array_keys($result['channels']));
    }

    public function testIsOkWhenNoChannelFailed(): void
    {
        $this->poller->method('poll')->willReturn($this->summary(2));

        $result = $this->now->run(new \DateTimeImmutable());

        $this->assertTrue($result['ok']);
        $this->assertSame(2, $result['channels']['sms']['requested']);
    }

    public function testIsNotOkWhenAChannelFailed(): void
    {
        $this->poller->method('poll')->willReturnCallback(
            fn (string $channel): array => 'sms' === $channel ? $this->summary(0, 'webhook returned HTTP 500.') : $this->summary(1)
        );

        $result = $this->now->run(new \DateTimeImmutable());

        $this->assertFalse($result['ok']);
        $this->assertSame('webhook returned HTTP 500.', $result['channels']['sms']['error']);
        $this->assertNull($result['channels']['email']['error']);
    }

    public function testAnUnexpectedExceptionBecomesTheChannelsError(): void
    {
        $this->poller->method('poll')->willThrowException(new \RuntimeException('database gone'));

        $result = $this->now->run(new \DateTimeImmutable());

        $this->assertFalse($result['ok']);
        $this->assertSame('database gone', $result['channels']['email']['error']);
        $this->assertSame(0, $result['channels']['email']['requested']);
    }

    public function testTheBatchSizeFollowsTheSettingsScreen(): void
    {
        $settings = $this->createMock(StatusPollSettings::class);
        $settings->method('current')->willReturn(['enabled' => false, 'interval' => 60, 'batch' => 40, 'timeout' => 180, 'maxDuration' => 300]);
        $batches = [];
        $this->poller->method('poll')->willReturnCallback(function (string $channel, int $batch) use (&$batches): array {
            $batches[] = $batch;

            return $this->summary();
        });

        (new StatusPollNow($this->poller, $settings))->run(new \DateTimeImmutable());

        $this->assertSame([40, 40, 40], $batches);
    }
}
