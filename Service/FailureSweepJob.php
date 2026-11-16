<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

use MauticPlugin\N8nDispatchBundle\Command\FailureSweepCommand;
use MauticPlugin\N8nDispatchBundle\Entity\PollRun;
use MauticPlugin\N8nDispatchBundle\Service\Alert\AlertSettings;

/**
 * The step failure sweep as a scheduled job (the Alertas tab holds its interval). It is never off: the cleanup of the
 * table must run with the alerts off too, and the alert log then shows what Mautic would have sent.
 */
class FailureSweepJob implements ScheduledJob
{
    public function __construct(private AlertSettings $settings)
    {
    }

    public function kind(): string
    {
        return PollRun::KIND_FAILURE_SWEEP;
    }

    public function command(): string
    {
        return FailureSweepCommand::NAME;
    }

    public function intervalMinutes(): ?int
    {
        return $this->settings->current()['failingInterval'];
    }
}
