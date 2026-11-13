<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\EventListener;

use MauticPlugin\N8nDispatchBundle\EventListener\ScheduledJobsSubscriber;
use MauticPlugin\N8nDispatchBundle\Service\JobScheduler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class ScheduledJobsSubscriberTest extends TestCase
{
    /** @var JobScheduler&MockObject */
    private JobScheduler $scheduler;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    private ScheduledJobsSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->scheduler  = $this->createMock(JobScheduler::class);
        $this->logger     = $this->createMock(LoggerInterface::class);
        $this->subscriber = new ScheduledJobsSubscriber($this->scheduler, $this->logger);
    }

    private function event(?string $commandName): ConsoleCommandEvent
    {
        return new ConsoleCommandEvent(null === $commandName ? null : new Command($commandName), new ArrayInput([]), new NullOutput());
    }

    public function testListensToTheConsoleCommandEvent(): void
    {
        $this->assertArrayHasKey('console.command', ScheduledJobsSubscriber::getSubscribedEvents());
    }

    #[DataProvider('cronCommands')]
    public function testTheJobsAreCheckedWhenAStandardCronCommandStarts(string $command): void
    {
        $this->scheduler->expects($this->once())->method('startDue');

        $this->subscriber->onCommand($this->event($command));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function cronCommands(): iterable
    {
        yield 'campaigns:trigger' => ['mautic:campaigns:trigger'];
        yield 'campaigns:update'  => ['mautic:campaigns:update'];
        yield 'segments:update'   => ['mautic:segments:update'];
    }

    #[DataProvider('otherCommands')]
    public function testOtherCommandsDoNotWakeTheJobs(?string $command): void
    {
        $this->scheduler->expects($this->never())->method('startDue');

        $this->subscriber->onCommand($this->event($command));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function otherCommands(): iterable
    {
        yield 'status poll'   => ['n8ndispatch:status:poll'];
        yield 'mirror resync' => ['n8ndispatch:mirror:resync'];
        yield 'cache:clear'   => ['cache:clear'];
        yield 'email fetch'   => ['mautic:email:fetch'];
        yield 'no command'    => [null];
    }

    public function testAFailureIsLoggedAndNeverReachesTheCommandThatWokeUsUp(): void
    {
        $this->scheduler->method('startDue')->willThrowException(new \RuntimeException('deadlock'));
        $this->logger->expects($this->once())->method('error')->with('N8nDispatch: could not start a scheduled job: deadlock');

        $this->subscriber->onCommand($this->event('mautic:campaigns:update'));
    }
}
