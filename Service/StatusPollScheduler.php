<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

use MauticPlugin\N8nDispatchBundle\Entity\PollRunRepository;

/**
 * Decides whether the status poll is due. Nothing here runs by itself:
 * EventListener/StatusPollSubscriber asks this each time one of Mautic's
 * standard cron commands starts — the cron every Mautic install must have
 * for its campaigns, which is also what makes the dispatches happen in the
 * first place — so the plugin needs no cron entry of its own.
 */
class StatusPollScheduler
{
    private const KEEP_RUNS_DAYS = 7;

    public function __construct(
        private StatusPollSettings $settings,
        private PollRunRepository $runs,
    ) {
    }

    /**
     * Returns the id of a newly claimed run, or null when the status poll is
     * off or it is not time yet.
     */
    public function tryStart(\DateTimeImmutable $now): ?int
    {
        $settings = $this->settings->current();

        if (!$settings['enabled']) {
            return null;
        }

        $runId = $this->runs->claim($now, $settings['interval']);

        if (null !== $runId) {
            $this->runs->prune($now->modify('-'.self::KEEP_RUNS_DAYS.' days'));
        }

        return $runId;
    }
}
