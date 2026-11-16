<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Entity;

use Doctrine\DBAL\ArrayParameterType;
use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * @extends CommonRepository<PollRun>
 */
class PollRunRepository extends CommonRepository
{
    public const RUNNING_STALE_MINUTES = 120;

    /** Runs of every kind older than this are dropped when a new run starts. */
    public const KEEP_RUNS_DAYS = 7;

    public function getTableAlias(): string
    {
        return 'pr';
    }

    /**
     * Starts a run if none started within the interval — one statement, so
     * the check and the insert cannot be split by another command starting
     * at the same moment. Returns the new run's id, or null when it is not
     * time yet.
     *
     * The interval is shortened by one minute: a cron that ticks every 15
     * minutes never fires at exactly the same second, and without that
     * margin a "15 minutes" interval would often miss a tick and wait for
     * the next one.
     *
     * The interval counts only the runs of the same kind. The lock covers the kinds in `$lockKinds` (the lane of the job,
     * Config/services.php): a run of one of them still "running" blocks the claim. A run "running" for longer than
     * RUNNING_STALE_MINUTES is taken as dead (crashed, or killed before it could close itself), so it cannot block
     * its lane forever.
     *
     * @param list<string> $lockKinds the kinds of the lane of `$kind`, `$kind` included
     */
    public function claim(\DateTimeImmutable $now, int $intervalMinutes, string $kind, array $lockKinds): ?int
    {
        if ([] === $lockKinds) {
            throw new \InvalidArgumentException('A run needs the kinds of its lane to take the lock.');
        }

        $connection = $this->getEntityManager()->getConnection();
        $table      = $this->getTableName();
        $utc        = new \DateTimeZone('UTC');
        $threshold  = $now->modify('-'.max(0, $intervalMinutes * 60 - 60).' seconds');
        $stale      = $now->modify('-'.self::RUNNING_STALE_MINUTES.' minutes');

        $inserted = $connection->executeStatement(
            "INSERT INTO {$table} (started_at, status, kind) SELECT :now, :status, :kind FROM DUAL "
            ."WHERE NOT EXISTS (SELECT 1 FROM {$table} WHERE kind = :same_kind AND started_at > :threshold) "
            ."AND NOT EXISTS (SELECT 1 FROM {$table} WHERE status = :status AND started_at > :stale AND kind IN (:lock_kinds))",
            [
                'now'        => $now->setTimezone($utc)->format('Y-m-d H:i:s'),
                'status'     => PollRun::STATUS_RUNNING,
                'kind'       => $kind,
                'same_kind'  => $kind,
                'threshold'  => $threshold->setTimezone($utc)->format('Y-m-d H:i:s'),
                'stale'      => $stale->setTimezone($utc)->format('Y-m-d H:i:s'),
                'lock_kinds' => array_values($lockKinds),
            ],
            ['lock_kinds' => ArrayParameterType::STRING]
        );

        return $inserted > 0 ? (int) $connection->lastInsertId() : null;
    }

    public function latest(string $kind): ?PollRun
    {
        return $this->createQueryBuilder('pr')
            ->where('pr.kind = :kind')
            ->setParameter('kind', $kind)
            ->orderBy('pr.startedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The newest runs of one kind, plain rows (the "Mirror" tab lists them).
     *
     * @return list<array{started_at: string, status: string, summary: string|null}>
     */
    public function latestRuns(string $kind, int $limit): array
    {
        return $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT started_at, status, summary FROM '.$this->getTableName().' WHERE kind = :kind ORDER BY started_at DESC, id DESC LIMIT '.max(1, $limit),
            ['kind' => $kind]
        );
    }

    /**
     * Ends a scheduled run. A null or unknown id does nothing: a command run by hand has no run to close.
     */
    public function close(?int $runId, string $status, string $summary, \DateTimeInterface $now): void
    {
        if (!in_array($status, [PollRun::STATUS_OK, PollRun::STATUS_ERROR], true)) {
            throw new \InvalidArgumentException('Unknown status "'.$status.'".');
        }

        if (null === $runId) {
            return;
        }

        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE '.$this->getTableName().' SET status = :status, summary = :summary, finished_at = :finished WHERE id = :id',
            [
                'status'   => $status,
                'summary'  => $summary,
                'finished' => \DateTimeImmutable::createFromInterface($now)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                'id'       => $runId,
            ]
        );
    }

    public function prune(\DateTimeImmutable $before): void
    {
        $this->createQueryBuilder('pr')
            ->delete()
            ->where('pr.startedAt < :before')
            ->setParameter('before', $before, 'datetime_immutable')
            ->getQuery()
            ->execute();
    }
}
