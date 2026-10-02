<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\EventListener;

use MauticPlugin\N8nDispatchBundle\EventListener\StatusPollSubscriber;
use MauticPlugin\N8nDispatchBundle\Service\StatusPollLauncher;
use MauticPlugin\N8nDispatchBundle\Service\StatusPollScheduler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class StatusPollSubscriberTest extends TestCase
{
    /** @var StatusPollScheduler&MockObject */
    private StatusPollScheduler $scheduler;

    /** @var StatusPollLauncher&MockObject */
    private StatusPollLauncher $launcher;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    private StatusPollSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->scheduler  = $this->createMock(StatusPollScheduler::class);
        $this->launcher   = $this->createMock(StatusPollLauncher::class);
        $this->logger     = $this->createMock(LoggerInterface::class);
        $this->subscriber = new StatusPollSubscriber($this->scheduler, $this->launcher, $this->logger);
    }

    private function event(?string $commandName): ConsoleCommandEvent
    {
        return new ConsoleCommandEvent(null === $commandName ? null : new Command($commandName), new ArrayInput([]), new NullOutput());
    }

    public function testListensToTheConsoleCommandEvent(): void
    {
        $this->assertArrayHasKey('console.command', StatusPollSubscriber::getSubscribedEvents());
    }

    /**
     * @dataProvider cronCommands
     */
    public function testALaunchHappensWhenAStandardCronCommandStartsAndItIsTime(string $command): void
    {
        $this->scheduler->method('tryStart')->willReturn(42);
        $this->launcher->expects($this->once())->method('launch')->with(42);

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

    public function testNothingIsLaunchedWhenItIsNotTimeYet(): void
    {
        $this->scheduler->method('tryStart')->willReturn(null);
        $this->launcher->expects($this->never())->method('launch');

        $this->subscriber->onCommand($this->event('mautic:campaigns:trigger'));
    }

    /**
     * @dataProvider otherCommands
     */
    public function testOtherCommandsAreNeitherCheckedNorLaunched(?string $command): void
    {
        $this->scheduler->expects($this->never())->method('tryStart');
        $this->launcher->expects($this->never())->method('launch');

        $this->subscriber->onCommand($this->event($command));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function otherCommands(): iterable
    {
        yield 'our own command' => ['n8ndispatch:status:poll'];
        yield 'cache:clear'     => ['cache:clear'];
        yield 'email fetch'     => ['mautic:email:fetch'];
        yield 'no command'      => [null];
    }

    public function testAFailureNeverReachesTheCommandThatWokeUsUp(): void
    {
        $this->scheduler->method('tryStart')->willThrowException(new \RuntimeException('database gone'));
        $this->logger->expects($this->once())->method('error');

        $this->subscriber->onCommand($this->event('mautic:campaigns:trigger'));
    }

    public function testALaunchFailureIsLoggedAndSwallowed(): void
    {
        $this->scheduler->method('tryStart')->willReturn(9);
        $this->launcher->method('launch')->willThrowException(new \RuntimeException('no shell'));
        $this->logger->expects($this->once())->method('error');

        $this->subscriber->onCommand($this->event('mautic:segments:update'));
    }
}
