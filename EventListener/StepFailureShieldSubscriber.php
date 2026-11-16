<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\EventListener;

use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Event\FailedEvent;
use MauticPlugin\N8nDispatchBundle\Entity\StepFailureRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Keeps a failing step of this plugin from switching the whole campaign off: it records the failure in our own table and
 * stops the event before core's listener (priority 0) counts it. Core still reschedules the failed log, so the contact is
 * tried again every hour. stopPropagation() also skips core's failed_count, its bell notification and every other
 * listener for these steps. Steps of other plugins are NOT shielded on purpose (plugins stay independent).
 * The why, the traps and the sweep: wiki/n8n-dispatch-failing-steps.md.
 */
class StepFailureShieldSubscriber implements EventSubscriberInterface
{
    /** Above every other listener of the event, ours included. */
    public const PRIORITY = 1000;

    private const STEP_TYPE_PREFIX = 'n8ndispatch.';

    public function __construct(private StepFailureRepository $failures, private LoggerInterface $logger)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [CampaignEvents::ON_EVENT_FAILED => ['shield', self::PRIORITY]];
    }

    public function shield(FailedEvent $event): void
    {
        $log  = $event->getLog();
        $step = $log->getEvent();

        if (!str_starts_with((string) $step->getType(), self::STEP_TYPE_PREFIX)) {
            return;
        }

        $event->stopPropagation();

        try {
            $this->failures->record(
                (int) $step->getId(),
                (int) $log->getLead()->getId(),
                (int) $step->getCampaign()->getId(),
                (int) $log->getId(),
                $log->getFailedLog()?->getReason(),
                new \DateTimeImmutable(),
            );
        } catch (\Throwable $e) {
            // never break core's failure handling: the contact is still rescheduled, only its count is lost
            $this->logger->error('N8nDispatch: failure of log '.$log->getId().' (step '.$step->getId().') not counted: '.$e->getMessage());
        }
    }
}
