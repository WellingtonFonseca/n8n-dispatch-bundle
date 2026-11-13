<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

/**
 * A job the plugin starts in the background on a Mautic cron tick (JobScheduler). The job says when it is due and which
 * command runs it; the claim, the lock and the launch are the same for every job.
 */
interface ScheduledJob
{
    /** The PollRun kind of its runs. The interval counts only the runs of the same kind. */
    public function kind(): string;

    /** The console command that does the work. It takes --run-id. */
    public function command(): string;

    /** Minutes between two runs, or null when the job is off. */
    public function intervalMinutes(): ?int;
}
