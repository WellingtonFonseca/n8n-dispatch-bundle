<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\EventListener;

use MauticPlugin\N8nDispatchBundle\Service\Alert\BounceSpikeAlerts;
use MauticPlugin\N8nDispatchBundle\Service\CronCommands;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Wakes the bounce check on the cron commands every Mautic install runs (the same ride as StatusPollSubscriber: the
 * plugin needs no cron entry). Nothing that goes wrong here may reach the command that woke it.
 */
class BounceSpikeCheckSubscriber implements EventSubscriberInterface
{
    public function __construct(private BounceSpikeAlerts $alerts, private LoggerInterface $logger)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [ConsoleEvents::COMMAND => 'onCommand'];
    }

    public function onCommand(ConsoleCommandEvent $event): void
    {
        if (!in_array($event->getCommand()?->getName(), CronCommands::NAMES, true)) {
            return;
        }

        try {
            $this->alerts->check(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        } catch (\Throwable $e) {
            $this->logger->error('N8nDispatch: bounce check failed: '.$e->getMessage());
        }
    }
}
