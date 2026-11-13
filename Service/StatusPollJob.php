<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

use MauticPlugin\N8nDispatchBundle\Command\StatusPollCommand;
use MauticPlugin\N8nDispatchBundle\Entity\PollRun;

/** The status poll as a scheduled job (the Features tab holds its switch and interval). */
class StatusPollJob implements ScheduledJob
{
    public function __construct(private StatusPollSettings $settings)
    {
    }

    public function kind(): string
    {
        return PollRun::KIND_STATUS_POLL;
    }

    public function command(): string
    {
        return StatusPollCommand::NAME;
    }

    public function intervalMinutes(): ?int
    {
        $settings = $this->settings->current();

        return $settings['enabled'] ? $settings['interval'] : null;
    }
}
