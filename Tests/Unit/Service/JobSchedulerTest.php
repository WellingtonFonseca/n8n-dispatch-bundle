<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service;

use MauticPlugin\N8nDispatchBundle\Entity\PollRun;
use MauticPlugin\N8nDispatchBundle\Entity\PollRunRepository;
use MauticPlugin\N8nDispatchBundle\Service\BackgroundCommandLauncher;
use MauticPlugin\N8nDispatchBundle\Service\JobScheduler;
use MauticPlugin\N8nDispatchBundle\Service\ScheduledJob;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[Group('area:status-poll')]
#[Group('area:mirror')]
#[Group('area:alerts')]
#[Group('risk:INFRA')]
#[Group('risk:CRITICO')]
class JobSchedulerTest extends TestCase
{
    /** @var PollRunRepository&MockObject */
    private PollRunRepository $runs;

    /** @var BackgroundCommandLauncher&MockObject */
    private BackgroundCommandLauncher $launcher;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->runs     = $this->createMock(PollRunRepository::class);
        $this->launcher = $this->createMock(BackgroundCommandLauncher::class);
        $this->logger   = $this->createMock(LoggerInterface::class);
        $this->now      = new \DateTimeImmutable('2026-11-13 10:00:00');
    }

    private function job(string $kind, ?int $interval): ScheduledJob
    {
        $job = $this->createStub(ScheduledJob::class);
        $job->method('kind')->willReturn($kind);
        $job->method('command')->willReturn('cmd:'.$kind);
        $job->method('intervalMinutes')->willReturn($interval);

        return $job;
    }

    /**
     * @param list<list<ScheduledJob>> $lanes
     */
    private function scheduler(array $lanes): JobScheduler
    {
        return new JobScheduler($lanes, $this->runs, $this->launcher, $this->logger);
    }

    public function testAJobThatIsOffIsNeverClaimed(): void
    {
        $this->runs->expects($this->never())->method('claim');
        $this->launcher->expects($this->never())->method('launch');

        $this->assertSame([], $this->scheduler([[$this->job('a', null)]])->startDue($this->now));
    }

    public function testADueJobIsClaimedWithItsIntervalAndItsLaneAndLaunchedWithTheRunId(): void
    {
        $job = $this->job('a', 90);
        $this->runs->expects($this->once())->method('claim')->with($this->now, 90, 'a', ['a'])->willReturn(42);
        $this->launcher->expects($this->once())->method('launch')->with('cmd:a', 42);

        $this->assertSame([$job], $this->scheduler([[$job]])->startDue($this->now));
    }

    public function testTheLockOfAJobCoversTheKindsOfItsWholeLane(): void
    {
        $this->runs->expects($this->once())->method('claim')->with($this->now, 60, 'poll', ['poll', 'resync'])->willReturn(1);

        $this->scheduler([[$this->job('poll', 60), $this->job('resync', 60)]])->startDue($this->now);
    }

    public function testOldRunsAreDroppedWhenARunStarts(): void
    {
        $this->runs->method('claim')->willReturn(7);
        $this->runs->expects($this->once())->method('prune')->with($this->equalTo(new \DateTimeImmutable('2026-11-06 10:00:00')));

        $this->scheduler([[$this->job('a', 60)]])->startDue($this->now);
    }

    public function testNothingIsLaunchedOrPrunedWhenItIsNotTimeYet(): void
    {
        $this->runs->method('claim')->willReturn(null);
        $this->runs->expects($this->never())->method('prune');
        $this->launcher->expects($this->never())->method('launch');

        $this->assertSame([], $this->scheduler([[$this->job('a', 60)]])->startDue($this->now));
    }

    public function testARunWhoseCommandDoesNotStartIsClosedAsAnErrorAndTheFailureReachesTheCaller(): void
    {
        $this->runs->method('claim')->willReturn(9);
        $this->launcher->method('launch')->willThrowException(new \RuntimeException('no shell'));
        $this->runs->expects($this->once())->method('close')->with(9, PollRun::STATUS_ERROR, 'no shell', $this->now);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no shell');

        $this->scheduler([[$this->job('a', 60)]])->startDue($this->now);
    }

    public function testInsideALaneTheFirstDueJobTakesTheTurnAndTheOthersWait(): void
    {
        $first  = $this->job('first', 60);
        $second = $this->job('second', 60);
        $this->runs->expects($this->once())->method('claim')->with($this->now, 60, 'first', ['first', 'second'])->willReturn(1);
        $this->launcher->expects($this->once())->method('launch')->with('cmd:first', 1);

        $this->assertSame([$first], $this->scheduler([[$first, $second]])->startDue($this->now));
    }

    public function testASecondJobOfTheLaneIsTriedWhenTheFirstIsOffOrNotDue(): void
    {
        $off    = $this->job('off', null);
        $notDue = $this->job('not-due', 60);
        $due    = $this->job('due', 30);
        $this->runs->method('claim')->willReturnCallback(static fn ($now, $interval, $kind): ?int => 'due' === $kind ? 5 : null);
        $this->launcher->expects($this->once())->method('launch')->with('cmd:due', 5);

        $this->assertSame([$due], $this->scheduler([[$off, $notDue, $due]])->startDue($this->now));
    }

    public function testOneTickStartsOneJobOfEveryLane(): void
    {
        $sweep = $this->job('sweep', 60);
        $poll  = $this->job('poll', 60);
        $this->runs->method('claim')->willReturnCallback(static fn ($now, $interval, $kind): int => 'sweep' === $kind ? 11 : 12);
        $launched = [];
        $this->launcher->method('launch')->willReturnCallback(static function (string $command, int $runId) use (&$launched): void {
            $launched[] = [$command, $runId];
        });

        $started = $this->scheduler([[$sweep], [$poll, $this->job('resync', 60)]])->startDue($this->now);

        $this->assertSame([$sweep, $poll], $started);
        $this->assertSame([['cmd:sweep', 11], ['cmd:poll', 12]], $launched);
    }

    public function testALaneThatIsNotDueDoesNotHoldBackTheNextLane(): void
    {
        $poll = $this->job('poll', 60);
        $this->runs->method('claim')->willReturnCallback(static fn ($now, $interval, $kind): ?int => 'poll' === $kind ? 3 : null);

        $this->assertSame([$poll], $this->scheduler([[$this->job('sweep', 60)], [$poll]])->startDue($this->now));
    }

    public function testALaneWhoseLaunchFailsDoesNotStopTheOtherLanesAndTheFirstErrorIsThrownAtTheEnd(): void
    {
        $this->runs->method('claim')->willReturnCallback(static fn ($now, $interval, $kind): int => 'sweep' === $kind ? 1 : 2);
        $launched = [];
        $this->launcher->method('launch')->willReturnCallback(static function (string $command) use (&$launched): void {
            if ('cmd:sweep' === $command) {
                throw new \RuntimeException('no shell for the sweep');
            }

            $launched[] = $command;
        });
        $this->runs->expects($this->once())->method('close')->with(1, PollRun::STATUS_ERROR, 'no shell for the sweep', $this->now);
        $this->runs->expects($this->once())->method('prune');
        $this->logger->expects($this->once())->method('error')->with('N8nDispatch: a scheduled job could not start: no shell for the sweep');

        try {
            $this->scheduler([[$this->job('sweep', 60)], [$this->job('poll', 60)]])->startDue($this->now);
            $this->fail('the launch error must reach the caller');
        } catch (\RuntimeException $e) {
            $this->assertSame('no shell for the sweep', $e->getMessage());
        }

        $this->assertSame(['cmd:poll'], $launched, 'the poll lane still started');
    }

    public function testAClaimThatFailsInOneLaneIsLoggedAndTheOtherLaneStillStarts(): void
    {
        $this->runs->method('claim')->willReturnCallback(static function ($now, $interval, string $kind): int {
            if ('sweep' === $kind) {
                throw new \RuntimeException('claim broke');
            }

            return 4;
        });
        $this->logger->expects($this->once())->method('error')->with('N8nDispatch: a scheduled job could not start: claim broke');
        $this->launcher->expects($this->once())->method('launch')->with('cmd:poll', 4);

        try {
            $this->scheduler([[$this->job('sweep', 60)], [$this->job('poll', 60)]])->startDue($this->now);
            $this->fail('the claim error must reach the caller');
        } catch (\RuntimeException $e) {
            $this->assertSame('claim broke', $e->getMessage());
        }
    }

    public function testAPruneThatFailsIsLoggedAndDoesNotReplaceTheErrorOfALane(): void
    {
        $this->runs->method('claim')->willReturnCallback(static fn ($now, $interval, string $kind): int => 'poll' === $kind ? 4 : 1);
        $this->launcher->method('launch')->willReturnCallback(static function (string $command): void {
            if ('cmd:sweep' === $command) {
                throw new \RuntimeException('no shell for the sweep');
            }
        });
        $this->runs->method('prune')->willThrowException(new \RuntimeException('prune broke'));
        $messages = [];
        $this->logger->method('error')->willReturnCallback(static function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        try {
            $this->scheduler([[$this->job('sweep', 60)], [$this->job('poll', 60)]])->startDue($this->now);
            $this->fail('an error must reach the caller');
        } catch (\RuntimeException $e) {
            $this->assertSame('no shell for the sweep', $e->getMessage(), 'the error of the lane stays the first one');
        }

        $this->assertSame([
            'N8nDispatch: a scheduled job could not start: no shell for the sweep',
            'N8nDispatch: old scheduled runs not dropped: prune broke',
        ], $messages);
    }

    public function testAPruneThatFailsAloneReachesTheCaller(): void
    {
        $this->runs->method('claim')->willReturn(4);
        $this->runs->method('prune')->willThrowException(new \RuntimeException('prune broke'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('prune broke');

        $this->scheduler([[$this->job('poll', 60)]])->startDue($this->now);
    }

    public function testWhenSeveralLanesFailTheFirstErrorIsTheOneThrown(): void
    {
        $this->runs->method('claim')->willReturn(1);
        $this->launcher->method('launch')->willReturnCallback(static function (string $command): void {
            throw new \RuntimeException('no shell for '.$command);
        });

        try {
            $this->scheduler([[$this->job('sweep', 60)], [$this->job('poll', 60)]])->startDue($this->now);
            $this->fail('the launch error must reach the caller');
        } catch (\RuntimeException $e) {
            $this->assertSame('no shell for cmd:sweep', $e->getMessage());
        }
    }

    public function testRunsAreDroppedOnceNoMatterHowManyJobsStarted(): void
    {
        $this->runs->method('claim')->willReturn(5);
        $this->runs->expects($this->once())->method('prune');

        $this->scheduler([[$this->job('a', 60)], [$this->job('b', 60)]])->startDue($this->now);
    }
}
