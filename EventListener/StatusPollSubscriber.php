<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\EventListener;

use MauticPlugin\N8nDispatchBundle\Service\StatusPollLauncher;
use MauticPlugin\N8nDispatchBundle\Service\StatusPollScheduler;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Gives the status poll its schedule without a cron entry of its own: it
 * rides on the cron commands every Mautic install already runs for its
 * campaigns and segments. Symfony announces a command the moment it starts,
 * before it looks for work, so this wakes up on every cron tick, busy or
 * not. The scheduler then decides whether the poll is due.
 *
 * Nothing that goes wrong here may reach the command that woke us up.
 */
class StatusPollSubscriber implements EventSubscriberInterface
{
    private const CRON_COMMANDS = [
        'mautic:campaigns:trigger',
        'mautic:campaigns:update',
        'mautic:segments:update',
    ];

    public function __construct(
        private StatusPollScheduler $scheduler,
        private StatusPollLauncher $launcher,
        private LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleEvents::COMMAND => 'onCommand',
        ];
    }

    public function onCommand(ConsoleCommandEvent $event): void
    {
        $name = $event->getCommand()?->getName();

        if (!in_array($name, self::CRON_COMMANDS, true)) {
            return;
        }

        try {
            $runId = $this->scheduler->tryStart(new \DateTimeImmutable());

            if (null !== $runId) {
                $this->launcher->launch($runId);
            }
        } catch (\Throwable $e) {
            $this->logger->error('N8nDispatch: could not start the status poll: '.$e->getMessage());
        }
    }
}
