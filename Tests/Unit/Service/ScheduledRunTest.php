<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service;

use MauticPlugin\N8nDispatchBundle\Entity\PollRun;
use MauticPlugin\N8nDispatchBundle\Entity\PollRunRepository;
use MauticPlugin\N8nDispatchBundle\Service\ScheduledRun;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

#[Group('area:status-poll')]
#[Group('area:mirror')]
#[Group('area:alerts')]
#[Group('risk:INFRA')]
#[Group('risk:DADOS')]
class ScheduledRunTest extends TestCase
{
    /** @var PollRunRepository&MockObject */
    private PollRunRepository $runs;

    private ScheduledRun $scheduled;

    protected function setUp(): void
    {
        $this->runs      = $this->createMock(PollRunRepository::class);
        $this->scheduled = new ScheduledRun($this->runs);
    }

    public function testTheOptionIsAddedWithTheTextOfTheScheduler(): void
    {
        $command = new class('zz:test') extends Command {
        };

        ScheduledRun::addOption($command);

        $option = $command->getDefinition()->getOption('run-id');
        $this->assertTrue($option->isValueRequired());
        $this->assertStringContainsString('the run to close when this one ends', $option->getDescription());
    }

    public function testTheRunIdIsReadAsANumberAndIsNullForARunByHand(): void
    {
        $definition = new InputDefinition([new InputOption('run-id', null, InputOption::VALUE_REQUIRED)]);

        $this->assertSame(42, ScheduledRun::id(new ArrayInput(['--run-id' => '42'], $definition)));
        $this->assertNull(ScheduledRun::id(new ArrayInput([], $definition)));
    }

    public function testAnOkResultClosesTheRunAsOkAndIsReturned(): void
    {
        $this->runs->expects($this->once())->method('close')->with(7, PollRun::STATUS_OK, 'asked 3', $this->isInstanceOf(\DateTimeImmutable::class));
        $onErrorCalls = 0;

        $result = $this->scheduled->run(7, static fn (): array => ['ok' => true, 'summary' => 'asked 3'], static function () use (&$onErrorCalls): void {
            ++$onErrorCalls;
        });

        $this->assertSame(['ok' => true, 'summary' => 'asked 3'], $result);
        $this->assertSame(0, $onErrorCalls, 'no alert for a run that went well');
    }

    public function testAResultThatIsNotOkClosesTheRunAsAnErrorAndCallsOnErrorAfterTheClose(): void
    {
        $order = [];
        $this->runs->expects($this->once())->method('close')->willReturnCallback(function (?int $id, string $status, string $summary) use (&$order): void {
            $order[] = "close:$id:$status:$summary";
        });

        $result = $this->scheduled->run(7, static fn (): array => ['ok' => false, 'summary' => 'HTTP 500'], static function (int $id, string $summary) use (&$order): void {
            $order[] = "alert:$id:$summary";
        });

        $this->assertSame(['ok' => false, 'summary' => 'HTTP 500'], $result);
        $this->assertSame(['close:7:error:HTTP 500', 'alert:7:HTTP 500'], $order);
    }

    public function testAnExceptionClosesTheRunAsAnErrorWithItsMessageCallsOnErrorAndGoesOn(): void
    {
        $this->runs->expects($this->once())->method('close')->with(7, PollRun::STATUS_ERROR, 'database is gone', $this->anything());
        $alerts = [];

        try {
            $this->scheduled->run(7, static function (): array {
                throw new \RuntimeException('database is gone');
            }, static function (int $id, string $summary) use (&$alerts): void {
                $alerts[] = [$id, $summary];
            });
            $this->fail('the exception must go on');
        } catch (\RuntimeException $e) {
            $this->assertSame('database is gone', $e->getMessage());
        }

        $this->assertSame([[7, 'database is gone']], $alerts);
    }

    public function testARunByHandPassesANullIdAndRaisesNoAlertEvenOnAnError(): void
    {
        $this->runs->expects($this->exactly(2))->method('close')->with(null, $this->anything(), $this->anything(), $this->anything());
        $alerts = 0;
        $onError = static function () use (&$alerts): void {
            ++$alerts;
        };

        $this->scheduled->run(null, static fn (): array => ['ok' => false, 'summary' => 'x'], $onError);
        try {
            $this->scheduled->run(null, static function (): array {
                throw new \LogicException('y');
            }, $onError);
        } catch (\LogicException) {
        }

        $this->assertSame(0, $alerts);
    }

    public function testNoOnErrorIsFine(): void
    {
        $this->runs->expects($this->once())->method('close')->with(3, PollRun::STATUS_ERROR, 'bad', $this->anything());

        $this->assertSame(['ok' => false, 'summary' => 'bad'], $this->scheduled->run(3, static fn (): array => ['ok' => false, 'summary' => 'bad']));
    }
}
