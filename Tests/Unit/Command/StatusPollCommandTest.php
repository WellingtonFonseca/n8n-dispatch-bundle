<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Command;

use MauticPlugin\N8nDispatchBundle\Command\StatusPollCommand;
use MauticPlugin\N8nDispatchBundle\Entity\PollRun;
use MauticPlugin\N8nDispatchBundle\Entity\PollRunRepository;
use MauticPlugin\N8nDispatchBundle\Service\Alert\AlertSender;
use MauticPlugin\N8nDispatchBundle\Service\StatusPoller;
use MauticPlugin\N8nDispatchBundle\Service\StatusPollSettings;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[AllowMockObjectsWithoutExpectations]
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
        $settings = $this->createStub(StatusPollSettings::class);
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

    public function testAManualRunHasNoRunIdToClose(): void
    {
        $this->poller->method('poll')->willReturn($this->summary(2));
        $this->runs->expects($this->once())->method('close')->with(null, PollRun::STATUS_OK, $this->anything(), $this->anything());

        $this->tester->execute([]);
    }

    public function testAClaimedRunIsFinishedAsOkWithTheSummary(): void
    {
        $this->runs->expects($this->once())->method('close')->with(
            42,
            PollRun::STATUS_OK,
            $this->logicalAnd($this->stringContains('email: asked 3'), $this->stringContains('hsm: asked 3')),
            $this->isInstanceOf(\DateTimeInterface::class)
        );
        $this->poller->method('poll')->willReturn($this->summary(3));

        $this->assertSame(0, $this->tester->execute(['--run-id' => '42']));
    }

    public function testAClaimedRunIsFinishedAsErrorWhenAChannelFailed(): void
    {
        $this->runs->expects($this->once())->method('close')->with(7, PollRun::STATUS_ERROR, $this->stringContains('sms: webhook returned HTTP 500.'), $this->anything());
        $this->poller->method('poll')->willReturnCallback(
            fn (string $channel): array => 'sms' === $channel ? $this->summary(0, 'webhook returned HTTP 500.') : $this->summary(1)
        );

        $this->assertSame(1, $this->tester->execute(['--run-id' => '7']));
    }

    public function testAnUnexpectedExceptionStillClosesTheClaimedRun(): void
    {
        $this->runs->expects($this->once())->method('close')->with(7, PollRun::STATUS_ERROR, 'boom', $this->anything());
        $this->poller->method('poll')->willThrowException(new \RuntimeException('boom'));

        try {
            $this->tester->execute(['--run-id' => '7']);
            $this->fail('the exception should still reach the console');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }
    }

    private function commandWithAlerts(AlertSender $alerts): CommandTester
    {
        $settings = $this->createMock(StatusPollSettings::class);
        $settings->method('current')->willReturn(['enabled' => false, 'interval' => 60, 'batch' => 70, 'timeout' => 180, 'maxDuration' => 300]);

        return new CommandTester(new StatusPollCommand($this->poller, $this->runs, $settings, $alerts));
    }

    public function testARunThatEndsInErrorRaisesTheStatusPollAlertOncePerSixHours(): void
    {
        $this->poller->method('poll')->willReturn($this->summary(0, 'webhook returned HTTP 500.'));
        $alerts = $this->createMock(AlertSender::class);
        $alerts->expects($this->once())->method('send')->with(
            'status_poll.failed',
            $this->callback(static fn (array $data): bool => str_contains($data['summary'], 'webhook returned HTTP 500.')),
            'status_poll',
            21600
        );

        $this->commandWithAlerts($alerts)->execute(['--run-id' => '7']);
    }

    public function testARunThatEndsOkRaisesNoAlert(): void
    {
        $this->poller->method('poll')->willReturn($this->summary(2));
        $alerts = $this->createMock(AlertSender::class);
        $alerts->expects($this->never())->method('send');

        $this->commandWithAlerts($alerts)->execute(['--run-id' => '7']);
    }

    public function testAnExceptionThatClosesTheRunAsErrorRaisesTheAlertToo(): void
    {
        $this->poller->method('poll')->willThrowException(new \RuntimeException('boom'));
        $alerts = $this->createMock(AlertSender::class);
        $alerts->expects($this->once())->method('send')->with('status_poll.failed', $this->anything(), 'status_poll', 21600);

        try {
            $this->commandWithAlerts($alerts)->execute(['--run-id' => '7']);
        } catch (\RuntimeException) {
        }
    }

    public function testAManualRunThatFailsRaisesNoAlert(): void
    {
        $this->poller->method('poll')->willReturn($this->summary(0, 'webhook returned HTTP 500.'));
        $alerts = $this->createMock(AlertSender::class);
        $alerts->expects($this->never())->method('send');

        $this->commandWithAlerts($alerts)->execute([]);
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
