<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Command;

use MauticPlugin\N8nDispatchBundle\Command\FailureSweepCommand;
use MauticPlugin\N8nDispatchBundle\Entity\PollRun;
use MauticPlugin\N8nDispatchBundle\Entity\PollRunRepository;
use MauticPlugin\N8nDispatchBundle\Service\Alert\FailingStepAlerts;
use MauticPlugin\N8nDispatchBundle\Service\ScheduledRun;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[Group('area:alerts')]
#[Group('risk:INFRA')]
class FailureSweepCommandTest extends TestCase
{
    /** @var FailingStepAlerts&MockObject */
    private FailingStepAlerts $alerts;

    /** @var PollRunRepository&MockObject */
    private PollRunRepository $runs;

    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->alerts = $this->createMock(FailingStepAlerts::class);
        $this->runs   = $this->createMock(PollRunRepository::class);
        $this->tester = new CommandTester(new FailureSweepCommand($this->alerts, new ScheduledRun($this->runs)));
    }

    public function testARunPrintsTheSummaryAndClosesTheScheduledRunAsOk(): void
    {
        $this->alerts->expects($this->once())->method('sweep')->with($this->isInstanceOf(\DateTimeImmutable::class))->willReturn(['cleaned' => 2, 'steps' => 1, 'contacts' => 5]);
        $this->runs->expects($this->once())->method('close')->with(42, PollRun::STATUS_OK, 'cleaned 2, failing steps 1, stuck contacts 5', $this->anything());

        $this->assertSame(Command::SUCCESS, $this->tester->execute(['--run-id' => '42']));
        $this->assertSame("cleaned 2, failing steps 1, stuck contacts 5\n", $this->tester->getDisplay());
    }

    public function testARunByHandHasNoRunToClose(): void
    {
        $this->alerts->method('sweep')->willReturn(['cleaned' => 0, 'steps' => 0, 'contacts' => 0]);
        $this->runs->expects($this->once())->method('close')->with(null, PollRun::STATUS_OK, $this->anything(), $this->anything());

        $this->tester->execute([]);
    }

    public function testAFailedSweepClosesTheRunAsAnErrorAndRethrows(): void
    {
        $this->alerts->method('sweep')->willThrowException(new \RuntimeException('database is gone'));
        $this->runs->expects($this->once())->method('close')->with(42, PollRun::STATUS_ERROR, 'database is gone', $this->anything());

        $this->expectException(\RuntimeException::class);

        $this->tester->execute(['--run-id' => '42']);
    }
}
