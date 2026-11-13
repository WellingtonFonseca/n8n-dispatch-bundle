<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

use MauticPlugin\N8nDispatchBundle\Command\MirrorResyncCommand;
use MauticPlugin\N8nDispatchBundle\Entity\PollRun;

/** The Mirror resync as a scheduled job (the Features tab holds its switch and interval). */
class MirrorResyncJob implements ScheduledJob
{
    public function __construct(private MirrorResyncSettings $settings)
    {
    }

    public function kind(): string
    {
        return PollRun::KIND_MIRROR_RESYNC;
    }

    public function command(): string
    {
        return MirrorResyncCommand::NAME;
    }

    public function intervalMinutes(): ?int
    {
        $settings = $this->settings->current();

        return $settings['enabled'] ? $settings['interval'] : null;
    }
}
