<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Command;

use MauticPlugin\N8nDispatchBundle\Command\MirrorResyncCommand;
use MauticPlugin\N8nDispatchBundle\Entity\PollRun;
use MauticPlugin\N8nDispatchBundle\Entity\PollRunRepository;
use MauticPlugin\N8nDispatchBundle\Service\MirrorResync;
use MauticPlugin\N8nDispatchBundle\Service\MirrorResyncSettings;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The resync command.
 */
#[AllowMockObjectsWithoutExpectations]
class MirrorResyncCommandTest extends TestCase
{
    /** @var MirrorResync&MockObject */
    private MirrorResync $resync;

    /** @var PollRunRepository&MockObject */
    private PollRunRepository $runs;

    private CommandTester $tester;

    private const DONE = ['due' => 5, 'calls' => 3, 'ok' => 2, 'failed' => 1, 'skipped' => 0, 'left' => 2, 'error' => null];

    protected function setUp(): void
    {
        $this->resync = $this->createMock(MirrorResync::class);
        $this->runs   = $this->createMock(PollRunRepository::class);
        $settings     = $this->createMock(MirrorResyncSettings::class);
        $settings->method('current')->willReturn(['enabled' => false, 'interval' => 120, 'batch' => 70, 'delay' => 1500, 'refreshHours' => 12]);
        $this->tester = new CommandTester(new MirrorResyncCommand($this->resync, $this->runs, $settings));
    }

    public function testARunUsesTheSettingsAndPrintsTheSummary(): void
    {
        $this->resync->expects($this->once())->method('run')->with(70, 1500, 12, $this->isInstanceOf(\DateTimeImmutable::class))->willReturn(self::DONE);

        $this->assertSame(Command::SUCCESS, $this->tester->execute([]));
        $this->assertSame("due 5, calls 3, ok 2, failed 1, skipped 0, left 2\n", $this->tester->getDisplay());
    }

    public function testTheOptionsOverrideTheSettings(): void
    {
        $this->resync->expects($this->once())->method('run')->with(3, 0, 12, $this->anything())->willReturn(self::DONE);

        $this->tester->execute(['--batch' => '3', '--delay' => '0']);
    }

    public function testEmailIdSendsOnlyThatEmail(): void
    {
        $this->resync->expects($this->never())->method('run');
        $this->resync->expects($this->once())->method('runOne')->with(42)->willReturn(self::DONE);

        $this->tester->execute(['--email-id' => '42']);
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function invalidOptions(): iterable
    {
        yield 'batch 0' => [['--batch' => '0']];
        yield 'batch text' => [['--batch' => 'abc']];
        yield 'delay below 0' => [['--delay' => '-1']];
        yield 'email id 0' => [['--email-id' => '0']];
    }

    /**
     * @param array<string, string> $options
     */
    #[DataProvider('invalidOptions')]
    public function testAnInvalidOptionStopsBeforeAnything(array $options): void
    {
        $this->resync->expects($this->never())->method('run');
        $this->resync->expects($this->never())->method('runOne');

        $this->assertSame(Command::INVALID, $this->tester->execute($options));
    }

    public function testAnErrorEndsInFailureAndClosesTheRunAsError(): void
    {
        $this->runs->expects($this->once())->method('close')->with(
            9,
            PollRun::STATUS_ERROR,
            'due 5, calls 3, ok 2, failed 1, skipped 0, left 2; error: the integration is off or has no webhook URL',
            $this->anything()
        );
        $this->resync->method('run')->willReturn(['error' => 'the integration is off or has no webhook URL'] + self::DONE);

        $this->assertSame(Command::FAILURE, $this->tester->execute(['--run-id' => '9']));
    }

    public function testAScheduledRunIsClosedAsOkWithTheSummary(): void
    {
        $this->runs->expects($this->once())->method('close')->with(9, PollRun::STATUS_OK, 'due 5, calls 3, ok 2, failed 1, skipped 0, left 2', $this->anything());
        $this->resync->method('run')->willReturn(self::DONE);

        $this->tester->execute(['--run-id' => '9']);
    }

    public function testAThrownErrorStillClosesTheRun(): void
    {
        $this->runs->expects($this->once())->method('close')->with(9, PollRun::STATUS_ERROR, 'db gone', $this->anything());
        $this->resync->method('run')->willThrowException(new \RuntimeException('db gone'));

        try {
            $this->tester->execute(['--run-id' => '9']);
            $this->fail('the error must not be swallowed');
        } catch (\RuntimeException $e) {
            $this->assertSame('db gone', $e->getMessage());
        }
    }

    public function testAManualRunHasNoRunIdToClose(): void
    {
        $this->resync->method('run')->willReturn(self::DONE);
        $this->runs->expects($this->once())->method('close')->with(null, PollRun::STATUS_OK, $this->anything(), $this->anything());

        $this->tester->execute([]);
    }
}
