<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\EventListener;

use MauticPlugin\N8nDispatchBundle\Service\CronCommands;
use MauticPlugin\N8nDispatchBundle\Service\JobScheduler;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Wakes the scheduled jobs (status poll, Mirror resync) on the cron commands every Mautic install runs for its
 * campaigns and segments. Symfony announces a command the moment it starts, so this runs on every cron tick, busy or
 * not. Nothing that goes wrong here may reach the command that woke us up.
 */
class ScheduledJobsSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private JobScheduler $scheduler,
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
        if (!in_array($event->getCommand()?->getName(), CronCommands::NAMES, true)) {
            return;
        }

        try {
            $this->scheduler->startDue(new \DateTimeImmutable());
        } catch (\Throwable $e) {
            $this->logger->error('N8nDispatch: could not start a scheduled job: '.$e->getMessage());
        }
    }
}
