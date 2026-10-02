<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * @extends CommonRepository<DispatchCallback>
 */
class DispatchCallbackRepository extends CommonRepository
{
    public function getTableAlias(): string
    {
        return 'dc';
    }

    /**
     * @param list<int> $trackingIds
     *
     * @return list<DispatchCallback> oldest first
     */
    public function findByTrackingIds(array $trackingIds): array
    {
        if ([] === $trackingIds) {
            return [];
        }

        return $this->createQueryBuilder('dc')
            ->where('dc.trackingId IN (:ids)')
            ->setParameter('ids', $trackingIds)
            ->orderBy('dc.receivedAt', 'ASC')
            ->addOrderBy('dc.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
