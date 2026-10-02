<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Command;

use MauticPlugin\N8nDispatchBundle\Command\StatusPollCommand;
use MauticPlugin\N8nDispatchBundle\Entity\PollRun;
use MauticPlugin\N8nDispatchBundle\Entity\PollRunRepository;
use MauticPlugin\N8nDispatchBundle\Service\StatusPoller;
use MauticPlugin\N8nDispatchBundle\Service\StatusPollSettings;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class StatusPollCommandTest extends TestCase
{
    /** @var StatusPoller&MockObject */
    private StatusPoller $poller;

    /** @var PollRunRepository&MockObject */
    private PollRunRepository $runs;

    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->poller = $this->createMock(StatusPoller::class);
        $this->runs   = $this->createMock(PollRunRepository::class);
        $settings = $this->createMock(StatusPollSettings::class);
        $settings->method('current')->willReturn(['enabled' => false, 'interval' => 60, 'batch' => 70, 'timeout' => 180, 'maxDuration' => 300]);
        $this->tester = new CommandTester(new StatusPollCommand($this->poller, $this->runs, $settings));
    }

    private function summary(int $requested = 0, ?string $error = null): array
    {
        return ['requested' => $requested, 'changed' => 0, 'unchanged' => $requested, 'unknown' => 0, 'invalid' => 0, 'calls' => $requested > 0 ? 1 : 0, 'error' => $error];
    }

    public function testPollsTheThreeChannelsByDefault(): void
    {
        $channels = [];
        $this->poller->method('poll')->willReturnCallback(function (string $channel) use (&$channels): array {
            $channels[] = $channel;

            return $this->summary();
        });

        $this->assertSame(0, $this->tester->execute([]));
        $this->assertSame(['email', 'sms', 'hsm'], $channels);
    }

    public function testAManualRunDoesNotTouchTheRunHistory(): void
    {
        $this->poller->method('poll')->willReturn($this->summary(2));
        $this->runs->expects($this->never())->method('find');
        $this->runs->expects($this->never())->method('saveEntity');

        $this->tester->execute([]);
    }

    public function testAClaimedRunIsFinishedAsOkWithTheSummary(): void
    {
        $run = PollRun::start(new \DateTimeImmutable('2026-10-02 10:00:00'));
        $this->runs->method('find')->with(42)->willReturn($run);
        $this->runs->expects($this->once())->method('saveEntity')->with($run);
        $this->poller->method('poll')->willReturn($this->summary(3));

        $this->assertSame(0, $this->tester->execute(['--run-id' => '42']));

        $this->assertSame(PollRun::STATUS_OK, $run->getStatus());
        $this->assertNotNull($run->getFinishedAt());
        $this->assertStringContainsString('email: asked 3', (string) $run->getSummary());
        $this->assertStringContainsString('hsm: asked 3', (string) $run->getSummary());
    }

    public function testAClaimedRunIsFinishedAsErrorWhenAChannelFailed(): void
    {
        $run = PollRun::start(new \DateTimeImmutable('2026-10-02 10:00:00'));
        $this->runs->method('find')->willReturn($run);
        $this->poller->method('poll')->willReturnCallback(
            fn (string $channel): array => 'sms' === $channel ? $this->summary(0, 'webhook returned HTTP 500.') : $this->summary(1)
        );

        $this->assertSame(1, $this->tester->execute(['--run-id' => '7']));

        $this->assertSame(PollRun::STATUS_ERROR, $run->getStatus());
        $this->assertStringContainsString('sms: webhook returned HTTP 500.', (string) $run->getSummary());
    }

    public function testAnUnexpectedExceptionStillClosesTheClaimedRun(): void
    {
        $run = PollRun::start(new \DateTimeImmutable('2026-10-02 10:00:00'));
        $this->runs->method('find')->willReturn($run);
        $this->poller->method('poll')->willThrowException(new \RuntimeException('boom'));

        try {
            $this->tester->execute(['--run-id' => '7']);
            $this->fail('the exception should still reach the console');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame(PollRun::STATUS_ERROR, $run->getStatus());
        $this->assertStringContainsString('boom', (string) $run->getSummary());
    }

    public function testRejectsAnInvalidChannel(): void
    {
        $this->poller->expects($this->never())->method('poll');

        $this->assertSame(2, $this->tester->execute(['--channel' => 'fax']));
    }

    public function testTheBatchAndTheCapGoToThePoller(): void
    {
        $seen = [];
        $this->poller->method('poll')->willReturnCallback(function (string $channel, int $batch, int $maxAge, string $outcome, ?string $override, \DateTimeImmutable $now, int $maxCalls) use (&$seen): array {
            $seen[] = [$batch, $maxCalls];

            return $this->summary();
        });

        $this->tester->execute(['--batch' => '50', '--max-calls' => '7']);
        $this->assertSame([[50, 7], [50, 7], [50, 7]], $seen);

        $seen = [];
        $this->tester->execute([]);
        $this->assertSame([[70, 1000], [70, 1000], [70, 1000]], $seen, 'with no --batch the batch size is the one on the settings screen; a high cap');
    }

    public function testTheOutputSaysHowManyCallsWereMade(): void
    {
        $this->poller->method('poll')->willReturn(['requested' => 250, 'changed' => 10, 'unchanged' => 240, 'unknown' => 0, 'invalid' => 0, 'calls' => 3, 'error' => null]);

        $this->tester->execute(['--channel' => 'email']);

        $this->assertStringContainsString('asked 250', $this->tester->getDisplay());
        $this->assertStringContainsString('3 calls', $this->tester->getDisplay());
    }

    public function testRejectsAnInvalidBatchOrCap(): void
    {
        $this->poller->expects($this->never())->method('poll');

        $this->assertSame(2, $this->tester->execute(['--batch' => '0']));
        $this->assertSame(2, $this->tester->execute(['--max-calls' => 'abc']));
    }
}
