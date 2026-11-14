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

    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->runs     = $this->createMock(PollRunRepository::class);
        $this->launcher = $this->createMock(BackgroundCommandLauncher::class);
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

    public function testAJobThatIsOffIsNeverClaimed(): void
    {
        $this->runs->expects($this->never())->method('claim');
        $this->launcher->expects($this->never())->method('launch');

        $this->assertNull((new JobScheduler([$this->job('a', null)], $this->runs, $this->launcher))->startDue($this->now));
    }

    public function testADueJobIsClaimedWithItsIntervalAndLaunchedWithTheRunId(): void
    {
        $job = $this->job('a', 90);
        $this->runs->expects($this->once())->method('claim')->with($this->now, 90, 'a')->willReturn(42);
        $this->launcher->expects($this->once())->method('launch')->with('cmd:a', 42);

        $this->assertSame($job, (new JobScheduler([$job], $this->runs, $this->launcher))->startDue($this->now));
    }

    public function testOldRunsAreDroppedWhenARunStarts(): void
    {
        $this->runs->method('claim')->willReturn(7);
        $this->runs->expects($this->once())->method('prune')->with($this->equalTo(new \DateTimeImmutable('2026-11-06 10:00:00')));

        (new JobScheduler([$this->job('a', 60)], $this->runs, $this->launcher))->startDue($this->now);
    }

    public function testNothingIsLaunchedOrPrunedWhenItIsNotTimeYet(): void
    {
        $this->runs->method('claim')->willReturn(null);
        $this->runs->expects($this->never())->method('prune');
        $this->launcher->expects($this->never())->method('launch');

        $this->assertNull((new JobScheduler([$this->job('a', 60)], $this->runs, $this->launcher))->startDue($this->now));
    }

    public function testARunWhoseCommandDoesNotStartIsClosedAsAnErrorAndTheFailureReachesTheCaller(): void
    {
        $this->runs->method('claim')->willReturn(9);
        $this->launcher->method('launch')->willThrowException(new \RuntimeException('no shell'));
        $this->runs->expects($this->once())->method('close')->with(9, PollRun::STATUS_ERROR, 'no shell', $this->now);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no shell');

        (new JobScheduler([$this->job('a', 60)], $this->runs, $this->launcher))->startDue($this->now);
    }

    public function testTheFirstDueJobTakesTheTurnAndTheOthersWait(): void
    {
        $first  = $this->job('first', 60);
        $second = $this->job('second', 60);
        $this->runs->expects($this->once())->method('claim')->with($this->now, 60, 'first')->willReturn(1);
        $this->launcher->expects($this->once())->method('launch')->with('cmd:first', 1);

        $this->assertSame($first, (new JobScheduler([$first, $second], $this->runs, $this->launcher))->startDue($this->now));
    }

    public function testASecondJobIsTriedWhenTheFirstIsOffOrNotDue(): void
    {
        $off     = $this->job('off', null);
        $notDue  = $this->job('not-due', 60);
        $due     = $this->job('due', 30);
        $this->runs->method('claim')->willReturnCallback(static fn ($now, $interval, $kind): ?int => 'due' === $kind ? 5 : null);
        $this->launcher->expects($this->once())->method('launch')->with('cmd:due', 5);

        $this->assertSame($due, (new JobScheduler([$off, $notDue, $due], $this->runs, $this->launcher))->startDue($this->now));
    }
}
