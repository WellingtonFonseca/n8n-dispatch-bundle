<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Resolver;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadList;
use Mautic\LeadBundle\Segment\ContactSegmentFilter;
use MauticPlugin\CustomObjectsBundle\Entity\CustomField;
use MauticPlugin\CustomObjectsBundle\Helper\QueryFilterHelper;
use MauticPlugin\CustomObjectsBundle\Provider\CustomFieldTypeProvider;
use MauticPlugin\CustomObjectsBundle\Segment\Query\Filter\CustomFieldFilterQueryBuilder;
use MauticPlugin\CustomObjectsBundle\Segment\Query\Filter\CustomItemNameFilterQueryBuilder;

/**
 * The SQL half of CustomObjectVariableResolver: answers "which of this
 * contact's Custom Items match this one segment condition?".
 *
 * A segment only asks whether a matching item EXISTS for the contact, so
 * it never returns the item itself. To match exactly what the segment
 * matched, findPositiveItemIds() builds the condition with the Custom
 * Objects plugin's own QueryFilterHelper — the same calls its
 * CustomFieldFilterQueryBuilder / CustomItemNameFilterQueryBuilder make
 * for the segment (same operators, same value handling, relative dates
 * already resolved by Mautic's filter decorators) — and only swaps the
 * selected column from the contact id to the item id.
 *
 * Like those builders, it returns the "positive" set: for operators the
 * segment applies as NOT EXISTS (e.g. 'neq', 'empty'), the returned items
 * are the ones the contact must NOT have; CustomObjectVariableResolver
 * turns that into "every other item" with findAllItemIds().
 *
 * Only items linked directly to the contact are considered (the segment
 * builders' first-level query) — item-to-item relations aren't used by
 * this project's objects.
 */
class SegmentItemMatcher
{
    private const ALIAS = 'n8nd';

    public function __construct(
        private EntityManagerInterface $em,
        private QueryFilterHelper $queryFilterHelper,
        private CustomFieldTypeProvider $customFieldTypeProvider,
    ) {
    }

    public function isContactInSegment(LeadList $segment, Lead $contact): bool
    {
        return false !== $this->em->getConnection()->createQueryBuilder()
            ->select('1')
            ->from(MAUTIC_TABLE_PREFIX.'lead_lists_leads', 'll')
            ->where('ll.leadlist_id = :segmentId')
            ->andWhere('ll.lead_id = :contactId')
            ->andWhere('ll.manually_removed = 0')
            ->setParameter('segmentId', $segment->getId())
            ->setParameter('contactId', $contact->getId())
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * @return int[]
     */
    public function findPositiveItemIds(ContactSegmentFilter $filter, Lead $contact, int $customObjectId): array
    {
        $alias = self::ALIAS;

        if (CustomItemNameFilterQueryBuilder::getServiceId() === $filter->getQueryType()) {
            $queryBuilder = $this->queryFilterHelper->createItemNameQueryBuilder($alias);
            $queryBuilder->andWhere($queryBuilder->expr()->eq("{$alias}_item.custom_object_id", ':n8ndObjectId'))
                ->setParameter('n8ndObjectId', $customObjectId);
            $this->queryFilterHelper->addCustomObjectNameExpression(
                $queryBuilder,
                $alias,
                (string) $filter->getOperator(),
                (string) $filter->getParameterValue()
            );
            $queryBuilder->select("DISTINCT {$alias}_item.id");
        } elseif (CustomFieldFilterQueryBuilder::getServiceId() === $filter->getQueryType()) {
            // 'true' = same flag CustomFieldFilterQueryBuilder passes: for
            // NOT EXISTS operators it builds the positive condition.
            $unionQueryContainer = $this->queryFilterHelper->createValueQuery($alias, $filter, true);
            $unionQueryContainer->rewind();
            $queryBuilder = $unionQueryContainer->current();
            $queryBuilder->select("DISTINCT {$alias}_value.custom_item_id");
        } else {
            return [];
        }

        $queryBuilder->andWhere($queryBuilder->expr()->eq("{$alias}_contact.contact_id", ':n8ndContactId'))
            ->setParameter('n8ndContactId', $contact->getId());

        return array_map('intval', $queryBuilder->executeQuery()->fetchFirstColumn());
    }

    /**
     * @return int[]
     */
    public function findAllItemIds(Lead $contact, int $customObjectId): array
    {
        $ids = $this->em->getConnection()->createQueryBuilder()
            ->select('DISTINCT ci.id')
            ->from(MAUTIC_TABLE_PREFIX.'custom_item_xref_contact', 'cix')
            ->innerJoin('cix', MAUTIC_TABLE_PREFIX.'custom_item', 'ci', 'ci.id = cix.custom_item_id')
            ->where('cix.contact_id = :contactId')
            ->andWhere('ci.custom_object_id = :objectId')
            ->setParameter('contactId', $contact->getId())
            ->setParameter('objectId', $customObjectId)
            ->executeQuery()
            ->fetchFirstColumn();

        return array_map('intval', $ids);
    }

    /**
     * Raw stored values of $field for the given items, ordered by item id.
     *
     * @param int[] $itemIds
     *
     * @return string[]
     */
    public function fetchFieldValues(array $itemIds, CustomField $field): array
    {
        $table = $this->customFieldTypeProvider->getType((string) $field->getType())->getTableName();

        $values = $this->em->getConnection()->createQueryBuilder()
            ->select('civ.value')
            ->from(MAUTIC_TABLE_PREFIX.$table, 'civ')
            ->where('civ.custom_field_id = :fieldId')
            ->andWhere('civ.custom_item_id IN (:itemIds)')
            ->orderBy('civ.custom_item_id')
            ->setParameter('fieldId', $field->getId())
            ->setParameter('itemIds', $itemIds, ArrayParameterType::INTEGER)
            ->executeQuery()
            ->fetchFirstColumn();

        return array_map('strval', $values);
    }
}
