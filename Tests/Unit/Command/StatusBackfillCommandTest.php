<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Command;

use MauticPlugin\N8nDispatchBundle\Command\StatusBackfillCommand;
use MauticPlugin\N8nDispatchBundle\Service\StatusBackfill;
use MauticPlugin\N8nDispatchBundle\Service\StatusPoller;
use MauticPlugin\N8nDispatchBundle\Service\StatusPollSettings;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class StatusBackfillCommandTest extends TestCase
{
    /** @var StatusBackfill&MockObject */
    private StatusBackfill $backfill;

    /** @var StatusPoller&MockObject */
    private StatusPoller $poller;

    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->backfill = $this->createMock(StatusBackfill::class);
        $this->poller   = $this->createMock(StatusPoller::class);
        $settings       = $this->createMock(StatusPollSettings::class);
        $settings->method('current')->willReturn(['enabled' => false, 'interval' => 60, 'batch' => 70, 'timeout' => 180, 'maxDuration' => 300]);
        $this->tester = new CommandTester(new StatusBackfillCommand($this->backfill, $this->poller, $settings));
    }

    /**
     * @return array<string, int>
     */
    private function summary(int $scanned = 10): array
    {
        return ['scanned' => $scanned, 'eligible' => 8, 'registered' => 6, 'alreadyTracked' => 2, 'skipped' => 2];
    }

    /**
     * @return array<string, mixed>
     */
    private function pollSummary(): array
    {
        return ['requested' => 3, 'changed' => 1, 'unchanged' => 2, 'unknown' => 0, 'invalid' => 0, 'calls' => 1, 'error' => null];
    }

    public function testRunsWithTheDefaultsPrintsTheSummaryAndThenAsksN8nForTheStatuses(): void
    {
        $this->backfill->expects($this->once())->method('run')
            ->with(7, 500, false, $this->anything(), false)
            ->willReturn($this->summary());
        $channels = [];
        $this->poller->method('poll')->willReturnCallback(function (string $channel, int $batch, int $maxAge) use (&$channels): array {
            $channels[] = [$channel, $batch, $maxAge];

            return $this->pollSummary();
        });

        $this->assertSame(0, $this->tester->execute([]));

        $display = $this->tester->getDisplay();
        $this->assertStringContainsString('scanned 10', $display);
        $this->assertStringContainsString('registered 6', $display);
        $this->assertStringContainsString('already tracked 2', $display);
        $this->assertSame([['email', 70, 7], ['sms', 70, 7], ['hsm', 70, 7]], $channels, 'the poll looks as far back as the backfill');
        $this->assertStringContainsString('email: asked 3', $display);
    }

    public function testResetIsPassedOnAndSaidOut(): void
    {
        $this->backfill->expects($this->once())->method('run')->with(30, 500, false, $this->anything(), true)->willReturn($this->summary());
        $this->poller->method('poll')->willReturn($this->pollSummary());

        $this->assertSame(0, $this->tester->execute(['--since-days' => '30', '--reset' => true]));

        $this->assertStringContainsString('history was deleted', strtolower($this->tester->getDisplay()));
    }

    public function testNoPollSkipsTheQuestionToN8n(): void
    {
        $this->backfill->method('run')->willReturn($this->summary());
        $this->poller->expects($this->never())->method('poll');

        $this->assertSame(0, $this->tester->execute(['--no-poll' => true]));
    }

    public function testDryRunWritesNothingAndDoesNotPollOrReset(): void
    {
        $this->backfill->expects($this->once())->method('run')->with(30, 100, true, $this->anything(), true)
            ->willReturn(['scanned' => 3, 'eligible' => 3, 'registered' => 3, 'alreadyTracked' => 0, 'skipped' => 0]);
        $this->poller->expects($this->never())->method('poll');

        $this->assertSame(0, $this->tester->execute(['--since-days' => '30', '--batch' => '100', '--dry-run' => true, '--reset' => true]));

        $this->assertStringContainsString('dry run', strtolower($this->tester->getDisplay()));
        $this->assertStringContainsString('would be registered 3', $this->tester->getDisplay());
        $this->assertStringNotContainsString('deleted', strtolower($this->tester->getDisplay()));
    }

    public function testAFailedPollMakesTheCommandFail(): void
    {
        $this->backfill->method('run')->willReturn($this->summary());
        $this->poller->method('poll')->willReturn(['error' => 'HTTP 500'] + $this->pollSummary());

        $this->assertSame(1, $this->tester->execute([]));
        $this->assertStringContainsString('HTTP 500', $this->tester->getDisplay());
    }

    public function testRejectsInvalidNumbers(): void
    {
        $this->backfill->expects($this->never())->method('run');

        $this->assertSame(2, $this->tester->execute(['--since-days' => '0']));
        $this->assertSame(2, $this->tester->execute(['--batch' => 'abc']));
    }
}
