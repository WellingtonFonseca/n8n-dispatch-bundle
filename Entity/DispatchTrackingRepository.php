<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * @extends CommonRepository<DispatchTracking>
 */
class DispatchTrackingRepository extends CommonRepository
{
    public function getTableAlias(): string
    {
        return 'dt';
    }

    public function findOneByRef(string $refType, string $refValue): ?DispatchTracking
    {
        return $this->findOneBy(['refType' => $refType, 'refValue' => $refValue]);
    }

    /**
     * The rows still waiting for an answer (or, with another $outcome, the
     * ones to ask about again), dispatched since $since, oldest id first and
     * only those after $afterId. The id cursor is what lets a run walk the
     * whole queue in batches without ever asking about the same row twice,
     * even about one n8n left out of its answer (which keeps its place in
     * any other ordering).
     *
     * @return list<DispatchTracking>
     */
    public function findPending(string $channel, string $outcome, \DateTimeInterface $since, int $limit, int $afterId = 0): array
    {
        return $this->createQueryBuilder('dt')
            ->where('dt.channel = :channel')
            ->andWhere('dt.outcome = :outcome')
            ->andWhere('dt.dispatchedAt >= :since')
            ->andWhere('dt.id > :after')
            ->setParameter('channel', $channel)
            ->setParameter('outcome', $outcome)
            ->setParameter('since', $since, 'datetime_immutable')
            ->setParameter('after', $afterId)
            ->orderBy('dt.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param array<string, string> $refs refType => refValue
     *
     * @return list<DispatchTracking>
     */
    public function findByRefs(array $refs): array
    {
        if ([] === $refs) {
            return [];
        }

        $qb = $this->createQueryBuilder('dt');
        $or = $qb->expr()->orX();

        $i = 0;
        foreach ($refs as $refType => $refValue) {
            $or->add($qb->expr()->andX("dt.refType = :type{$i}", "dt.refValue = :value{$i}"));
            $qb->setParameter("type{$i}", $refType)->setParameter("value{$i}", $refValue);
            ++$i;
        }

        return $qb->where($or)->orderBy('dt.id', 'ASC')->getQuery()->getResult();
    }

    /**
     * Deletes every row of the table (the backfill's --reset).
     */
    public function deleteAll(): void
    {
        $this->getEntityManager()->createQuery('DELETE FROM '.DispatchTracking::class)->execute();
    }
}
