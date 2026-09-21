<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Resolver;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\CustomObjectsBundle\Entity\CustomField;
use MauticPlugin\CustomObjectsBundle\Model\CustomObjectModel;
use Psr\Log\LoggerInterface;

/**
 * Resolves a Custom Object variable ({source: 'custom_object', customObject,
 * customObjectField}) to a string, per contact, at dispatch time.
 *
 * There is no per-contact record of "which linked item matched the segment
 * filter" — segment membership is computed with a plain SQL EXISTS(...)
 * (see CustomObjectsBundle's CustomFieldFilterQueryBuilder), which never
 * carries the matched row forward. So instead of asking the campaign
 * builder to redefine the same condition on every variable (duplicated,
 * drifts from the segment over time), this finds the Custom Object
 * condition already stored on the campaign's own source Segment(s) and
 * reapplies that exact condition — live — against just this one contact's
 * linked Custom Items, to find which item(s) still match right now.
 *
 * If more than one item matches (e.g. a contact with two pending
 * documents), every matching item's field value is joined with "<br>" —
 * Mirror inserts the variable's value raw into the HTML, unescaped, so
 * "<br>" renders as a real line break rather than literal text.
 */
class CustomObjectVariableResolver
{
    private const VALUE_TABLE_BY_TYPE = [
        'text'     => 'custom_field_value_text',
        'date'     => 'custom_field_value_date',
        'datetime' => 'custom_field_value_datetime',
        'int'      => 'custom_field_value_int',
    ];

    private const SIMPLE_SQL_OPERATOR_BY_FILTER_OPERATOR = [
        '='   => '=',
        '!='  => '!=',
        'gt'  => '>',
        'gte' => '>=',
        'lt'  => '<',
        'lte' => '<=',
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private CustomObjectModel $customObjectModel,
        private LoggerInterface $logger,
    ) {
    }

    public function resolve(Lead $contact, Campaign $campaign, string $customObjectAlias, string $targetFieldAlias): string
    {
        $condition = $this->findSegmentCondition($campaign, $customObjectAlias);

        if (null === $condition) {
            $this->logger->warning(
                "N8nDispatch: no segment filter condition found for Custom Object '{$customObjectAlias}' on any of campaign {$campaign->getId()}'s source segments — cannot resolve variable for contact {$contact->getId()}."
            );

            return '';
        }

        $matchingItemIds = $this->findMatchingItemIds($contact, $condition);

        if ([] === $matchingItemIds) {
            return '';
        }

        return implode('<br>', $this->fetchFieldValues($matchingItemIds, $customObjectAlias, $targetFieldAlias));
    }

    /**
     * @return array{fieldId: int, type: string, operator: string, value: mixed}|null
     */
    private function findSegmentCondition(Campaign $campaign, string $customObjectAlias): ?array
    {
        foreach ($campaign->getLists() as $list) {
            $filters = $list->getFilters();

            if (!is_array($filters)) {
                continue;
            }

            foreach ($filters as $filter) {
                if (($filter['object'] ?? null) !== 'custom_object') {
                    continue;
                }

                $fieldRef = (string) ($filter['field'] ?? '');

                if (!str_starts_with($fieldRef, 'cmf_')) {
                    continue;
                }

                $fieldId = (int) substr($fieldRef, 4);
                /** @var CustomField|null $customField */
                $customField = $this->em->getRepository(CustomField::class)->find($fieldId);

                if (null === $customField || $customField->getCustomObject()->getAlias() !== $customObjectAlias) {
                    continue;
                }

                return [
                    'fieldId'  => $fieldId,
                    'type'     => (string) $customField->getType(),
                    'operator' => (string) ($filter['operator'] ?? '='),
                    'value'    => $filter['properties']['filter'] ?? $filter['filter'] ?? null,
                ];
            }
        }

        return null;
    }

    /**
     * @param array{fieldId: int, type: string, operator: string, value: mixed} $condition
     *
     * @return int[]
     */
    private function findMatchingItemIds(Lead $contact, array $condition): array
    {
        $valueTable = self::VALUE_TABLE_BY_TYPE[$condition['type']] ?? null;

        if (null === $valueTable) {
            $this->logger->warning("N8nDispatch: field type '{$condition['type']}' is not supported for Custom Object variable resolution yet.");

            return [];
        }

        $qb = $this->em->getConnection()->createQueryBuilder();
        $qb->select('civ.custom_item_id')
            ->from($valueTable, 'civ')
            ->innerJoin('civ', 'custom_item_xref_contact', 'cix', 'cix.custom_item_id = civ.custom_item_id')
            ->where('civ.custom_field_id = :fieldId')
            ->andWhere('cix.contact_id = :contactId')
            ->setParameter('fieldId', $condition['fieldId'])
            ->setParameter('contactId', $contact->getId());

        $this->applyOperator($qb, $condition['operator'], $this->resolveValue($condition['type'], $condition['value']));

        return array_map('intval', array_column($qb->executeQuery()->fetchAllAssociative(), 'custom_item_id'));
    }

    private function applyOperator(\Doctrine\DBAL\Query\QueryBuilder $qb, string $operator, ?string $value): void
    {
        if ('empty' === $operator) {
            $qb->andWhere("(civ.value IS NULL OR civ.value = '')");

            return;
        }

        if ('!empty' === $operator) {
            $qb->andWhere("(civ.value IS NOT NULL AND civ.value != '')");

            return;
        }

        if (isset(self::SIMPLE_SQL_OPERATOR_BY_FILTER_OPERATOR[$operator])) {
            $qb->andWhere('civ.value '.self::SIMPLE_SQL_OPERATOR_BY_FILTER_OPERATOR[$operator].' :val')
                ->setParameter('val', $value);

            return;
        }

        $likeValue = match ($operator) {
            'like', '!like', 'contains' => '%'.$value.'%',
            'startsWith'                => $value.'%',
            'endsWith'                  => '%'.$value,
            default                     => null,
        };

        if (null !== $likeValue) {
            $qb->andWhere('civ.value '.('!like' === $operator ? 'NOT LIKE' : 'LIKE').' :val')
                ->setParameter('val', $likeValue);

            return;
        }

        // Unsupported operator (between/in/regexp/etc.) — no items match rather
        // than silently returning everything.
        $qb->andWhere('1 = 0');
        $this->logger->warning("N8nDispatch: segment filter operator '{$operator}' is not supported for Custom Object variable resolution yet.");
    }

    private function resolveValue(string $type, mixed $rawValue): ?string
    {
        if (null === $rawValue) {
            return null;
        }

        if (in_array($type, ['date', 'datetime'], true) && is_string($rawValue) && !preg_match('/^\d{4}-\d{2}-\d{2}/', $rawValue)) {
            // Relative date, e.g. "+ 10 days" — same plain DateTime::modify()
            // approach core's own DateRelativeInterval decorator uses to
            // resolve this same kind of value for the live segment filter.
            $date = new \DateTime();
            $date->modify($rawValue);

            return $date->format('date' === $type ? 'Y-m-d' : 'Y-m-d H:i:s');
        }

        return (string) $rawValue;
    }

    /**
     * @param int[] $itemIds
     *
     * @return string[]
     */
    private function fetchFieldValues(array $itemIds, string $customObjectAlias, string $targetFieldAlias): array
    {
        $customObject = $this->customObjectModel->fetchEntityByAlias($customObjectAlias);
        $targetField  = null;

        foreach ($customObject->getCustomFields() as $field) {
            if ($field->getAlias() === $targetFieldAlias) {
                $targetField = $field;
                break;
            }
        }

        if (null === $targetField) {
            $this->logger->warning("N8nDispatch: field '{$targetFieldAlias}' not found on Custom Object '{$customObjectAlias}'.");

            return [];
        }

        $valueTable = self::VALUE_TABLE_BY_TYPE[$targetField->getType()] ?? null;

        if (null === $valueTable) {
            $this->logger->warning("N8nDispatch: field type '{$targetField->getType()}' is not supported for Custom Object variable resolution yet.");

            return [];
        }

        $qb = $this->em->getConnection()->createQueryBuilder();
        $qb->select('civ.value')
            ->from($valueTable, 'civ')
            ->where('civ.custom_field_id = :fieldId')
            ->andWhere($qb->expr()->in('civ.custom_item_id', ':itemIds'))
            ->setParameter('fieldId', $targetField->getId())
            ->setParameter('itemIds', $itemIds, ArrayParameterType::INTEGER);

        $values = array_map('strval', array_column($qb->executeQuery()->fetchAllAssociative(), 'value'));

        // Mautic stores date/datetime Custom Object field values as
        // 'Y-m-d'/'Y-m-d H:i:s' — reformatted to pt-BR for dispatch, on
        // request, same as VariableResolver::resolveContactField() does
        // for core contact fields.
        return array_map(
            static fn (string $value): string => BrazilianDateFormatter::format($value, $targetField->getType()),
            $values
        );
    }
}
