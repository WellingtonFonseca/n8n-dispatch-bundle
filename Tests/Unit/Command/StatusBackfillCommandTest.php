<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Command;

use MauticPlugin\N8nDispatchBundle\Command\StatusBackfillCommand;
use MauticPlugin\N8nDispatchBundle\Service\StatusBackfill;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class StatusBackfillCommandTest extends TestCase
{
    /** @var StatusBackfill&MockObject */
    private StatusBackfill $backfill;

    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->backfill = $this->createMock(StatusBackfill::class);
        $this->tester   = new CommandTester(new StatusBackfillCommand($this->backfill));
    }

    public function testRunsWithTheDefaultsAndPrintsTheSummary(): void
    {
        $this->backfill->expects($this->once())->method('run')
            ->with(7, 500, false, $this->anything())
            ->willReturn(['scanned' => 10, 'eligible' => 8, 'registered' => 6, 'alreadyTracked' => 2, 'skipped' => 2]);

        $this->assertSame(0, $this->tester->execute([]));

        $display = $this->tester->getDisplay();
        $this->assertStringContainsString('scanned 10', $display);
        $this->assertStringContainsString('registered 6', $display);
        $this->assertStringContainsString('already tracked 2', $display);
    }

    public function testDryRunSaysItWroteNothing(): void
    {
        $this->backfill->expects($this->once())->method('run')->with(30, 100, true, $this->anything())
            ->willReturn(['scanned' => 3, 'eligible' => 3, 'registered' => 3, 'alreadyTracked' => 0, 'skipped' => 0]);

        $this->assertSame(0, $this->tester->execute(['--since-days' => '30', '--batch' => '100', '--dry-run' => true]));

        $this->assertStringContainsString('dry run', strtolower($this->tester->getDisplay()));
        $this->assertStringContainsString('would be registered 3', $this->tester->getDisplay());
    }

    public function testRejectsInvalidNumbers(): void
    {
        $this->backfill->expects($this->never())->method('run');

        $this->assertSame(2, $this->tester->execute(['--since-days' => '0']));
        $this->assertSame(2, $this->tester->execute(['--batch' => 'abc']));
    }
}
