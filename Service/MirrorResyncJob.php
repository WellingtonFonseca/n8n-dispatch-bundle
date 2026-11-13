<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

use MauticPlugin\N8nDispatchBundle\Command\MirrorResyncCommand;
use MauticPlugin\N8nDispatchBundle\Entity\PollRun;

/** The Mirror resync as a scheduled job (MirrorSendSwitch turns it on or off, the Mirror tab holds its interval). */
class MirrorResyncJob implements ScheduledJob
{
    public function __construct(private MirrorResyncSettings $settings, private MirrorSendSwitch $sendSwitch)
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
        return $this->sendSwitch->isOn() ? $this->settings->current()['interval'] : null;
    }
}
