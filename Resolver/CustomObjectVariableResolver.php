<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Resolver;

use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Segment\ContactSegmentFilter;
use Mautic\LeadBundle\Segment\ContactSegmentFilterFactory;
use MauticPlugin\CustomObjectsBundle\Entity\CustomField;
use MauticPlugin\CustomObjectsBundle\Exception\NotFoundException;
use MauticPlugin\CustomObjectsBundle\Model\CustomObjectModel;
use MauticPlugin\CustomObjectsBundle\Segment\Query\Filter\CustomFieldFilterQueryBuilder;
use MauticPlugin\CustomObjectsBundle\Segment\Query\Filter\CustomItemNameFilterQueryBuilder;
use MauticPlugin\CustomObjectsBundle\Segment\Query\Filter\CustomObjectMergedFilterQueryBuilder;
use Psr\Log\LoggerInterface;

/**
 * Resolves a Custom Object variable ({source: 'custom_object', customObject,
 * customObjectField}) to a string, per contact, at dispatch time.
 *
 * A contact can have many items of the same object; the campaign's source
 * segment(s) say which ones count. The items used are the ones that
 * satisfy EVERY condition the segment has on this object:
 *
 * - The filter list is Mautic's own (ContactSegmentFilterFactory::
 *   getSegmentFilters()), i.e. after the same rewriting the segment build
 *   applies, and grouped the same way: an 'or' glue starts a new group,
 *   conditions inside a group are ANDed.
 * - Inside a group, all conditions on this object must hold for the SAME
 *   item (intersection). Groups add their items together (union), and so
 *   do several segments.
 * - Conditions on contact fields or on other objects don't narrow the
 *   items; they only decide segment membership, which is respected by
 *   skipping segments the contact isn't currently in.
 * - Operators the segment applies as NOT EXISTS keep every item except
 *   the ones matching the positive condition (see SegmentItemMatcher).
 *
 * If several items remain, their values are joined with "<br>" — Mirror
 * inserts the variable's value raw into the Email HTML, so it renders as
 * a line break.
 */
class CustomObjectVariableResolver
{
    /**
     * Operators CustomFieldFilterQueryBuilder applies as NOT EXISTS.
     */
    private const NEGATED_FIELD_OPERATORS = ['empty', 'neq', 'notLike', '!multiselect', '!between', 'notBetween'];

    /**
     * Operators CustomItemNameFilterQueryBuilder applies as NOT EXISTS.
     */
    private const NEGATED_ITEM_NAME_OPERATORS = ['empty', 'neq', 'notLike'];

    public function __construct(
        private ContactSegmentFilterFactory $segmentFilterFactory,
        private SegmentItemMatcher $itemMatcher,
        private CustomObjectModel $customObjectModel,
        private LoggerInterface $logger,
    ) {
    }

    public function resolve(Lead $contact, Campaign $campaign, string $customObjectAlias, string $targetFieldAlias): string
    {
        try {
            $customObject = $this->customObjectModel->fetchEntityByAlias($customObjectAlias);
        } catch (NotFoundException) {
            $this->logger->warning("N8nDispatch: Custom Object '{$customObjectAlias}' not found.");

            return '';
        }

        $objectId    = (int) $customObject->getId();
        $fieldIds    = [];
        $targetField = null;

        /** @var CustomField $field */
        foreach ($customObject->getCustomFields() as $field) {
            $fieldIds[] = (int) $field->getId();

            if ($field->getAlias() === $targetFieldAlias) {
                $targetField = $field;
            }
        }

        if (null === $targetField) {
            $this->logger->warning("N8nDispatch: field '{$targetFieldAlias}' not found on Custom Object '{$customObjectAlias}'.");

            return '';
        }

        $hasCondition = false;
        $itemIds      = [];

        foreach ($campaign->getLists() as $segment) {
            if (!$this->itemMatcher->isContactInSegment($segment, $contact)) {
                continue;
            }

            foreach ($this->groupByOr($this->segmentFilterFactory->getSegmentFilters($segment)) as $group) {
                $groupItemIds = null;

                foreach ($group as $filter) {
                    if (!$this->isConditionOnObject($filter, $objectId, $fieldIds)) {
                        continue;
                    }

                    $conditionItemIds = $this->findItemIds($filter, $contact, $objectId);
                    $groupItemIds     = null === $groupItemIds ? $conditionItemIds : array_intersect($groupItemIds, $conditionItemIds);
                }

                if (null !== $groupItemIds) {
                    $hasCondition = true;
                    $itemIds      = array_merge($itemIds, $groupItemIds);
                }
            }
        }

        if (!$hasCondition) {
            $this->logger->warning(
                "N8nDispatch: no segment filter condition found for Custom Object '{$customObjectAlias}' on campaign {$campaign->getId()}'s source segments the contact is in — cannot resolve variable for contact {$contact->getId()}."
            );

            return '';
        }

        $itemIds = array_values(array_unique($itemIds));
        sort($itemIds);

        if ([] === $itemIds) {
            return '';
        }

        // Mautic stores date/datetime values as 'Y-m-d'/'Y-m-d H:i:s' —
        // reformatted to pt-BR, same as VariableResolver does for contact
        // fields.
        $values = array_map(
            static fn (string $value): string => BrazilianDateFormatter::format($value, (string) $targetField->getType()),
            $this->itemMatcher->fetchFieldValues($itemIds, $targetField)
        );

        return implode('<br>', $values);
    }

    /**
     * @return ContactSegmentFilter[][]
     */
    private function groupByOr(iterable $filters): array
    {
        $groups  = [];
        $current = [];

        foreach ($filters as $filter) {
            if ('or' === strtolower((string) $filter->getGlue()) && [] !== $current) {
                $groups[] = $current;
                $current  = [];
            }

            $current[] = $filter;
        }

        if ([] !== $current) {
            $groups[] = $current;
        }

        return $groups;
    }

    /**
     * @param int[] $fieldIds
     */
    private function isConditionOnObject(ContactSegmentFilter $filter, int $objectId, array $fieldIds): bool
    {
        return match ($filter->getQueryType()) {
            CustomFieldFilterQueryBuilder::getServiceId()        => in_array((int) $filter->getField(), $fieldIds, true),
            CustomItemNameFilterQueryBuilder::getServiceId()     => (int) $filter->getField() === $objectId,
            CustomObjectMergedFilterQueryBuilder::getServiceId() => $this->warnMergedFilter(),
            default                                              => false,
        };
    }

    /**
     * The Custom Objects plugin's 'custom_object_merge_filter' setting
     * (off in this project) packs several conditions into one filter.
     * Not supported here; logged so it doesn't fail silently if enabled.
     */
    private function warnMergedFilter(): bool
    {
        $this->logger->warning("N8nDispatch: merged Custom Object segment filters ('custom_object_merge_filter') are not supported for variable resolution; condition ignored.");

        return false;
    }

    /**
     * @return int[]
     */
    private function findItemIds(ContactSegmentFilter $filter, Lead $contact, int $objectId): array
    {
        $positive = $this->itemMatcher->findPositiveItemIds($filter, $contact, $objectId);

        $negatedOperators = CustomItemNameFilterQueryBuilder::getServiceId() === $filter->getQueryType()
            ? self::NEGATED_ITEM_NAME_OPERATORS
            : self::NEGATED_FIELD_OPERATORS;

        if (!in_array($filter->getOperator(), $negatedOperators, true)) {
            return $positive;
        }

        return array_values(array_diff($this->itemMatcher->findAllItemIds($contact, $objectId), $positive));
    }
}
