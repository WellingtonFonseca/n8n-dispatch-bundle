<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

use MauticPlugin\N8nDispatchBundle\Entity\PollRun;
use MauticPlugin\N8nDispatchBundle\Entity\PollRunRepository;

/**
 * Decides whether the Mirror resync is due, each time a Mautic cron command starts (MirrorResyncSubscriber). The run
 * is claimed in n8n_dispatch_poll_run, the same table and lock as the status poll.
 */
class MirrorResyncScheduler
{
    public function __construct(
        private MirrorResyncSettings $settings,
        private PollRunRepository $runs,
    ) {
    }

    /** The id of a newly claimed run, or null when the resync is off or it is not time yet. */
    public function tryStart(\DateTimeImmutable $now): ?int
    {
        $settings = $this->settings->current();

        if (!$settings['enabled']) {
            return null;
        }

        return $this->runs->claim($now, $settings['interval'], PollRun::KIND_MIRROR_RESYNC);
    }
}
