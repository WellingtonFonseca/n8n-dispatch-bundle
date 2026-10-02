<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * @extends CommonRepository<PollRun>
 */
class PollRunRepository extends CommonRepository
{
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
     */
    public function claim(\DateTimeImmutable $now, int $intervalMinutes): ?int
    {
        $connection = $this->getEntityManager()->getConnection();
        $table      = $this->getTableName();
        $utc        = new \DateTimeZone('UTC');
        $threshold  = $now->modify('-'.max(0, $intervalMinutes * 60 - 60).' seconds');

        $inserted = $connection->executeStatement(
            "INSERT INTO {$table} (started_at, status) SELECT :now, :status FROM DUAL "
            ."WHERE NOT EXISTS (SELECT 1 FROM {$table} WHERE started_at > :threshold)",
            [
                'now'       => $now->setTimezone($utc)->format('Y-m-d H:i:s'),
                'status'    => PollRun::STATUS_RUNNING,
                'threshold' => $threshold->setTimezone($utc)->format('Y-m-d H:i:s'),
            ]
        );

        return $inserted > 0 ? (int) $connection->lastInsertId() : null;
    }

    public function latest(): ?PollRun
    {
        return $this->createQueryBuilder('pr')
            ->orderBy('pr.startedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
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
