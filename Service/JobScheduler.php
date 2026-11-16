<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

use MauticPlugin\N8nDispatchBundle\Entity\PollRun;
use MauticPlugin\N8nDispatchBundle\Entity\PollRunRepository;
use Psr\Log\LoggerInterface;

/**
 * Starts the scheduled jobs that are due, each time a Mautic cron command starts (ScheduledJobsSubscriber). Nothing
 * runs by itself, so the plugin needs no cron entry of its own. The run is claimed in n8n_dispatch_poll_run, which is
 * also the lock.
 *
 * The jobs are grouped in LANES. Inside a lane a run of any job that is still 'running' blocks the others, and the
 * first due job takes the turn (the status poll and the Mirror resync share a lane: both call n8n, one at a time).
 * Lanes do not block each other: one tick starts one job of every lane (the failure sweep has a lane of its own).
 */
class JobScheduler
{
    /** @param list<list<ScheduledJob>> $lanes */
    public function __construct(
        private array $lanes,
        private PollRunRepository $runs,
        private BackgroundCommandLauncher $launcher,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Starts the first due job of every lane in the background. Returns the jobs it started. A lane that fails (the claim
     * or the launch) does not stop the others: every error is logged and the first one is thrown at the end. A launch
     * error is also on its run row, which is already closed as an error.
     *
     * @return list<ScheduledJob>
     */
    public function startDue(\DateTimeImmutable $now): array
    {
        $started = [];
        $error   = null;

        foreach ($this->lanes as $lane) {
            try {
                $job = $this->startInLane($lane, $now);
            } catch (\Throwable $e) {
                $this->logger->error('N8nDispatch: a scheduled job could not start: '.$e->getMessage());
                $error ??= $e;

                continue;
            }

            if (null !== $job) {
                $started[] = $job;
            }
        }

        if ([] !== $started) {
            // not `$error ??= $this->prune()`: the right side is skipped while an error is set, and the old runs must go anyway
            $pruneError = $this->prune($now);
            $error ??= $pruneError;
        }

        if (null !== $error) {
            throw $error;
        }

        return $started;
    }

    /** Returns the error instead of throwing it: it must not replace the error of a lane that failed. */
    private function prune(\DateTimeImmutable $now): ?\Throwable
    {
        try {
            $this->runs->prune($now->modify('-'.PollRunRepository::KEEP_RUNS_DAYS.' days'));
        } catch (\Throwable $e) {
            $this->logger->error('N8nDispatch: old scheduled runs not dropped: '.$e->getMessage());

            return $e;
        }

        return null;
    }

    /**
     * @param list<ScheduledJob> $lane
     */
    private function startInLane(array $lane, \DateTimeImmutable $now): ?ScheduledJob
    {
        $lockKinds = array_map(static fn (ScheduledJob $job): string => $job->kind(), $lane);

        foreach ($lane as $job) {
            $interval = $job->intervalMinutes();

            if (null === $interval) {
                continue;
            }

            $runId = $this->runs->claim($now, $interval, $job->kind(), $lockKinds);

            if (null === $runId) {
                continue;
            }

            $this->launch($job, $runId, $now);

            return $job;
        }

        return null;
    }

    /** A run whose command did not start is closed as an error: left 'running' it would block its lane for two hours. */
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
