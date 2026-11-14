<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\EventListener;

use MauticPlugin\N8nDispatchBundle\EventListener\BounceSpikeCheckSubscriber;
use MauticPlugin\N8nDispatchBundle\Service\Alert\BounceSpikeAlerts;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

#[Group('area:alerts')]
#[Group('risk:CRITICO')]
#[Group('risk:INFRA')]
class BounceSpikeCheckSubscriberTest extends TestCase
{
    /** @var BounceSpikeAlerts&MockObject */
    private BounceSpikeAlerts $alerts;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->alerts = $this->createMock(BounceSpikeAlerts::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function onCommand(?string $name): void
    {
        $command = null === $name ? null : new Command($name);
        (new BounceSpikeCheckSubscriber($this->alerts, $this->logger))->onCommand(new ConsoleCommandEvent($command, new ArrayInput([]), new NullOutput()));
    }

    public function testItListensToTheCommandEvent(): void
    {
        $this->assertSame([ConsoleEvents::COMMAND => 'onCommand'], BounceSpikeCheckSubscriber::getSubscribedEvents());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function cronCommands(): array
    {
        return ['trigger' => ['mautic:campaigns:trigger'], 'update' => ['mautic:campaigns:update'], 'segments' => ['mautic:segments:update']];
    }

    /**
     * @dataProvider cronCommands
     */
    public function testEachCronCommandRunsTheCheck(string $name): void
    {
        $this->alerts->expects($this->once())->method('check');

        $this->onCommand($name);
    }

    public function testAnotherCommandRunsNothing(): void
    {
        $this->alerts->expects($this->never())->method('check');

        $this->onCommand('cache:clear');
    }

    public function testAnEventWithoutACommandRunsNothing(): void
    {
        $this->alerts->expects($this->never())->method('check');

        $this->onCommand(null);
    }

    public function testAFailureOfTheCheckIsLoggedAndNeverReachesTheCronCommand(): void
    {
        $this->alerts->method('check')->willThrowException(new \RuntimeException('db gone'));
        $this->logger->expects($this->once())->method('error')->with('N8nDispatch: bounce check failed: db gone');

        $this->onCommand('mautic:campaigns:trigger');
    }
}
