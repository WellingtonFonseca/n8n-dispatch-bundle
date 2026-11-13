<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Command;

use MauticPlugin\N8nDispatchBundle\Command\MirrorResyncCommand;
use MauticPlugin\N8nDispatchBundle\Entity\PollRun;
use MauticPlugin\N8nDispatchBundle\Entity\PollRunRepository;
use MauticPlugin\N8nDispatchBundle\EventListener\MirrorResyncSubscriber;
use MauticPlugin\N8nDispatchBundle\Service\BackgroundCommandLauncher;
use MauticPlugin\N8nDispatchBundle\Service\MirrorResync;
use MauticPlugin\N8nDispatchBundle\Service\MirrorResyncScheduler;
use MauticPlugin\N8nDispatchBundle\Service\MirrorResyncSettings;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The resync command and the subscriber that starts it from Mautic's cron.
 */
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
     * @dataProvider invalidOptions
     *
     * @param array<string, string> $options
     */
    public function testAnInvalidOptionStopsBeforeAnything(array $options): void
    {
        $this->resync->expects($this->never())->method('run');
        $this->resync->expects($this->never())->method('runOne');

        $this->assertSame(Command::INVALID, $this->tester->execute($options));
    }

    public function testAnErrorEndsInFailureAndClosesTheRunAsError(): void
    {
        $run = PollRun::start(new \DateTimeImmutable());
        $this->runs->method('find')->with(9)->willReturn($run);
        $this->runs->expects($this->once())->method('saveEntity')->with($run);
        $this->resync->method('run')->willReturn(['error' => 'the integration is off or has no webhook URL'] + self::DONE);

        $this->assertSame(Command::FAILURE, $this->tester->execute(['--run-id' => '9']));
        $this->assertSame(PollRun::STATUS_ERROR, $run->getStatus());
        $this->assertSame('due 5, calls 3, ok 2, failed 1, skipped 0, left 2; error: the integration is off or has no webhook URL', $run->getSummary());
    }

    public function testAScheduledRunIsClosedAsOkWithTheSummary(): void
    {
        $run = PollRun::start(new \DateTimeImmutable());
        $this->runs->method('find')->willReturn($run);
        $this->resync->method('run')->willReturn(self::DONE);

        $this->tester->execute(['--run-id' => '9']);

        $this->assertSame(PollRun::STATUS_OK, $run->getStatus());
        $this->assertSame('due 5, calls 3, ok 2, failed 1, skipped 0, left 2', $run->getSummary());
    }

    public function testAThrownErrorStillClosesTheRun(): void
    {
        $run = PollRun::start(new \DateTimeImmutable());
        $this->runs->method('find')->willReturn($run);
        $this->resync->method('run')->willThrowException(new \RuntimeException('db gone'));

        try {
            $this->tester->execute(['--run-id' => '9']);
            $this->fail('the error must not be swallowed');
        } catch (\RuntimeException $e) {
            $this->assertSame('db gone', $e->getMessage());
        }

        $this->assertSame(PollRun::STATUS_ERROR, $run->getStatus());
        $this->assertSame('db gone', $run->getSummary());
    }

    public function testAManualRunDoesNotTouchTheRunHistory(): void
    {
        $this->resync->method('run')->willReturn(self::DONE);
        $this->runs->expects($this->never())->method('find');
        $this->runs->expects($this->never())->method('saveEntity');

        $this->tester->execute([]);
    }

    private function cronEvent(string $name): ConsoleCommandEvent
    {
        return new ConsoleCommandEvent(new Command($name), new ArrayInput([]), new NullOutput());
    }

    public function testTheSubscriberStartsAClaimedRunOnAMauticCronCommand(): void
    {
        $scheduler = $this->createMock(MirrorResyncScheduler::class);
        $scheduler->method('tryStart')->willReturn(11);
        $launcher = $this->createMock(BackgroundCommandLauncher::class);
        $launcher->expects($this->once())->method('launch')->with('n8ndispatch:mirror:resync', 11);

        (new MirrorResyncSubscriber($scheduler, $launcher, $this->createMock(LoggerInterface::class)))->onCommand($this->cronEvent('mautic:campaigns:trigger'));
    }

    public function testTheSubscriberIgnoresOtherCommandsAndRunsNotDue(): void
    {
        $scheduler = $this->createMock(MirrorResyncScheduler::class);
        $scheduler->expects($this->once())->method('tryStart')->willReturn(null);
        $launcher = $this->createMock(BackgroundCommandLauncher::class);
        $launcher->expects($this->never())->method('launch');
        $subscriber = new MirrorResyncSubscriber($scheduler, $launcher, $this->createMock(LoggerInterface::class));

        $subscriber->onCommand($this->cronEvent('cache:clear'));
        $subscriber->onCommand($this->cronEvent('mautic:segments:update'));
    }

    public function testTheSubscriberLogsAFailureAndNeverThrows(): void
    {
        $scheduler = $this->createMock(MirrorResyncScheduler::class);
        $scheduler->method('tryStart')->willThrowException(new \RuntimeException('deadlock'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with('N8nDispatch: could not start the Mirror resync: deadlock');

        (new MirrorResyncSubscriber($scheduler, $this->createMock(BackgroundCommandLauncher::class), $logger))->onCommand($this->cronEvent('mautic:campaigns:update'));
    }
}
