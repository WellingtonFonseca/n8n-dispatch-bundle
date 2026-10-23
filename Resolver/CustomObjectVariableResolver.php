<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Resolver;

use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\CustomObjectsBundle\Entity\CustomField;
use MauticPlugin\CustomObjectsBundle\Exception\NotFoundException;
use MauticPlugin\CustomObjectsBundle\Model\CustomObjectModel;
use MauticPlugin\CustomObjectsBundle\Segment\SegmentItemMatcher;
use MauticPlugin\CustomObjectsBundle\Segment\SegmentItemResolver;
use Psr\Log\LoggerInterface;

/**
 * Resolves a Custom Object variable ({source: 'custom_object', customObject,
 * customObjectField}) to a string, per contact, at dispatch time.
 *
 * A contact can have many items of the same object; the campaign's source
 * segment(s) say which ones count: the Custom Objects plugin's
 * SegmentItemResolver gives the items that satisfy EVERY condition the
 * segments have on this object (its rules are described there).
 *
 * If several items remain, their values are joined with $separator:
 * "<br>" by default, because Mirror inserts the value raw into the Email
 * HTML where it renders as a line break; SMS passes ", ".
 */
class CustomObjectVariableResolver
{
    public function __construct(
        private SegmentItemResolver $itemResolver,
        private SegmentItemMatcher $itemMatcher,
        private CustomObjectModel $customObjectModel,
        private LoggerInterface $logger,
    ) {
    }

    public function resolve(Lead $contact, Campaign $campaign, string $customObjectAlias, string $targetFieldAlias, string $separator = '<br>', string $language = LocaleConventions::DEFAULT_LOCALE): string
    {
        try {
            $customObject = $this->customObjectModel->fetchEntityByAlias($customObjectAlias);
        } catch (NotFoundException) {
            $this->logger->warning("N8nDispatch: Custom Object '{$customObjectAlias}' not found.");

            return '';
        }

        $targetField = null;

        /** @var CustomField $field */
        foreach ($customObject->getCustomFields() as $field) {
            if ($field->getAlias() === $targetFieldAlias) {
                $targetField = $field;
            }
        }

        if (null === $targetField) {
            $this->logger->warning("N8nDispatch: field '{$targetFieldAlias}' not found on Custom Object '{$customObjectAlias}'.");

            return '';
        }

        $itemIds = $this->itemResolver->resolveItemIds($contact, $campaign->getLists(), $customObject);

        if (null === $itemIds) {
            $this->logger->warning(
                "N8nDispatch: no segment filter condition found for Custom Object '{$customObjectAlias}' on campaign {$campaign->getId()}'s source segments the contact is in — cannot resolve variable for contact {$contact->getId()}."
            );

            return '';
        }

        if ([] === $itemIds) {
            return '';
        }

        // Mautic stores date/datetime values as 'Y-m-d'/'Y-m-d H:i:s' —
        // reformatted to the template's language, same as VariableResolver
        // does for contact fields. Decimal values lose their storage padding
        // and follow the language's separator (DecimalFormatter); each
        // formatter ignores the other's types.
        $values = array_map(
            static fn (string $value): string => DecimalFormatter::format(
                DateFormatter::format($value, (string) $targetField->getType(), $language),
                (string) $targetField->getType(),
                $language
            ),
            $this->itemMatcher->fetchFieldValues($itemIds, $targetField)
        );

        // An unfilled field is stored as '' or NULL: leave it out instead of
        // producing an empty entry and a stray separator.
        return implode($separator, array_filter($values, static fn (string $value): bool => '' !== $value));
    }
}
