<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\EventListener;

use Mautic\LeadBundle\Event\LeadTimelineEvent;
use Mautic\LeadBundle\LeadEvents;
use MauticPlugin\N8nDispatchBundle\Twig\DispatchExtension;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Reads the dispatch of every campaign card of a Timeline page in one query, so the cards do not each read their own.
 * It runs after core's campaign listener (lowest priority), when the page's events are all added.
 */
class TimelineDispatchPreloadSubscriber implements EventSubscriberInterface
{
    private const LAST = -1000;

    public function __construct(private readonly DispatchExtension $dispatches)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [LeadEvents::TIMELINE_ON_GENERATE => ['preload', self::LAST]];
    }

    public function preload(LeadTimelineEvent $event): void
    {
        if (!$event->isForTimeline()) {
            return;
        }

        $logIds = [];
        foreach ($event->getEvents() as $entry) {
            $logId = $entry['extra']['log']['log_id'] ?? null;
            if (null !== $logId && in_array($entry['event'] ?? '', ['campaign.event', 'campaign.event.scheduled'], true)) {
                $logIds[] = (int) $logId;
            }
        }

        if ([] !== $logIds) {
            $this->dispatches->preload(array_values(array_unique($logIds)));
        }
    }
}
