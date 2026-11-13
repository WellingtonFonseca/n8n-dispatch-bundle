<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\EventListener;

use MauticPlugin\N8nDispatchBundle\Command\MirrorResyncCommand;
use MauticPlugin\N8nDispatchBundle\Service\BackgroundCommandLauncher;
use MauticPlugin\N8nDispatchBundle\Service\CronCommands;
use MauticPlugin\N8nDispatchBundle\Service\MirrorResyncScheduler;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Gives the Mirror resync its schedule on the cron commands every Mautic install runs, like StatusPollSubscriber.
 * Nothing that goes wrong here may reach the command that woke us up.
 */
class MirrorResyncSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private MirrorResyncScheduler $scheduler,
        private BackgroundCommandLauncher $launcher,
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
            $runId = $this->scheduler->tryStart(new \DateTimeImmutable());

            if (null !== $runId) {
                $this->launcher->launch(MirrorResyncCommand::NAME, $runId);
            }
        } catch (\Throwable $e) {
            $this->logger->error('N8nDispatch: could not start the Mirror resync: '.$e->getMessage());
        }
    }
}
