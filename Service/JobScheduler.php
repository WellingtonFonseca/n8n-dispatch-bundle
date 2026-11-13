<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

use MauticPlugin\N8nDispatchBundle\Entity\PollRun;
use MauticPlugin\N8nDispatchBundle\Entity\PollRunRepository;

/**
 * Starts the scheduled jobs that are due, each time a Mautic cron command starts (ScheduledJobsSubscriber). Nothing
 * runs by itself, so the plugin needs no cron entry of its own. The run is claimed in n8n_dispatch_poll_run, which is
 * also the lock: a run of any kind still 'running' blocks every kind. The jobs are tried in the order given, so the
 * first one takes the turn when two are due at once.
 */
class JobScheduler
{
    /** @param list<ScheduledJob> $jobs */
    public function __construct(
        private array $jobs,
        private PollRunRepository $runs,
        private BackgroundCommandLauncher $launcher,
    ) {
    }

    /** Starts the first due job in the background. Returns it, or null when no job is due. */
    public function startDue(\DateTimeImmutable $now): ?ScheduledJob
    {
        foreach ($this->jobs as $job) {
            $interval = $job->intervalMinutes();

            if (null === $interval) {
                continue;
            }

            $runId = $this->runs->claim($now, $interval, $job->kind());

            if (null === $runId) {
                continue;
            }

            $this->launch($job, $runId, $now);
            $this->runs->prune($now->modify('-'.PollRunRepository::KEEP_RUNS_DAYS.' days'));

            return $job;
        }

        return null;
    }

    /** A run whose command did not start is closed as an error: left 'running' it would block every job for two hours. */
    private function launch(ScheduledJob $job, int $runId, \DateTimeImmutable $now): void
    {
        try {
            $this->launcher->launch($job->command(), $runId);
        } catch (\Throwable $e) {
            $this->runs->close($runId, PollRun::STATUS_ERROR, $e->getMessage(), $now);

            throw $e;
        }
    }
}
