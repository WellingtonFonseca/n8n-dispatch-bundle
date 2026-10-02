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
     * ones to ask about again), dispatched since $since. Never-checked rows
     * come first, then the ones checked longest ago, so a batch limit
     * rotates through the whole queue instead of re-asking the same ids.
     *
     * @return list<DispatchTracking>
     */
    public function findPending(string $channel, string $outcome, \DateTimeInterface $since, int $limit): array
    {
        return $this->createQueryBuilder('dt')
            ->where('dt.channel = :channel')
            ->andWhere('dt.outcome = :outcome')
            ->andWhere('dt.dispatchedAt >= :since')
            ->setParameter('channel', $channel)
            ->setParameter('outcome', $outcome)
            ->setParameter('since', $since, 'datetime_immutable')
            ->orderBy('dt.lastCheckedAt', 'ASC')
            ->addOrderBy('dt.id', 'ASC')
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
}
