<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\EventListener;

use Mautic\LeadBundle\Event\LeadTimelineEvent;
use Mautic\LeadBundle\LeadEvents;
use MauticPlugin\N8nDispatchBundle\Service\DispatchLogReader;
use MauticPlugin\N8nDispatchBundle\Twig\DispatchExtension;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Queues the dispatch cards of a Timeline page (core's campaign cards of our three steps) in DispatchExtension, which reads them all
 * in one query when the first one renders; a page without one reads nothing. It runs after core's campaign listener (priority 0),
 * which adds the cards.
 */
class TimelineDispatchQueueSubscriber implements EventSubscriberInterface
{
    private const AFTER_CORE = -1000;

    public function __construct(private readonly DispatchExtension $dispatches)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [LeadEvents::TIMELINE_ON_GENERATE => ['queueDispatchCards', self::AFTER_CORE]];
    }

    public function queueDispatchCards(LeadTimelineEvent $event): void
    {
        if (!$event->isForTimeline()) {
            return;
        }

        $ourTypes = DispatchLogReader::eventTypes();
        $logIds   = [];
        foreach ($event->getEvents() as $entry) {
            $log = $entry['extra']['log'] ?? [];
            // only core's campaign listener adds an entry whose log is of one of our step types
            if (isset($log['log_id']) && in_array($log['type'] ?? '', $ourTypes, true)) {
                $logIds[(int) $log['log_id']] = true;
            }
        }

        if ([] !== $logIds) {
            $this->dispatches->queue(array_keys($logIds));
        }
    }
}
