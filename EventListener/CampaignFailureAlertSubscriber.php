<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\EventListener;

use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Event\FailedEvent;
use MauticPlugin\N8nDispatchBundle\Service\Alert\CampaignFailureAlerts;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Wraps core's handling of a failed campaign step (CampaignEventSubscriber, priority 0): it remembers whether the
 * campaign was published before, and after core has counted the failure it raises the alerts. Core gives no event for
 * "unpublished by failures", so the before/after comparison is the only way to tell it from a manual unpublish.
 */
class CampaignFailureAlertSubscriber implements EventSubscriberInterface
{
    /** @var array<int, bool> campaign id => was published before core handled the failure */
    private array $wasPublished = [];

    public function __construct(private CampaignFailureAlerts $alerts, private LoggerInterface $logger)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CampaignEvents::ON_EVENT_FAILED => [
                ['rememberState', 100],
                ['raiseAlerts', -100],
            ],
        ];
    }

    public function rememberState(FailedEvent $event): void
    {
        $campaign = $event->getLog()->getEvent()->getCampaign();

        $this->wasPublished[(int) $campaign->getId()] = $campaign->isPublished();
    }

    public function raiseAlerts(FailedEvent $event): void
    {
        $log        = $event->getLog();
        $campaignId = (int) $log->getEvent()->getCampaign()->getId();
        $before     = $this->wasPublished[$campaignId] ?? false;

        unset($this->wasPublished[$campaignId]);

        try {
            $this->alerts->afterFailure($log, $before);
        } catch (\Throwable $e) {
            // never break core's failure handling because of an alert
            $this->logger->error('N8nDispatch: alert of a failed step not raised: '.$e->getMessage());
        }
    }
}
