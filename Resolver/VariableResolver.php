<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Resolver;

use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;

/**
 * Resolves the full variablesJson config (see Form/Type/EmailDispatchActionType.php
 * for its shape) to a flat {variableName: string} map for one contact.
 */
class VariableResolver
{
    public function __construct(
        private CustomObjectVariableResolver $customObjectVariableResolver,
        private LeadModel $leadModel,
    ) {
    }

    /**
     * $language is the locale of the template being sent (Email/SMS/HSM),
     * used to format dates and decimals — see LocaleConventions.
     *
     * $multiValueSeparator joins a Custom Object variable's values when
     * several items match: '<br>' for Email HTML (the default), ', ' for
     * SMS, where '<br>' would show up as literal text.
     *
     * @param array<string, array<string, mixed>> $variablesConfig
     *
     * @return array<string, string>
     */
    public function resolveAll(array $variablesConfig, Lead $contact, Campaign $campaign, string $multiValueSeparator = '<br>', string $language = LocaleConventions::DEFAULT_LOCALE): array
    {
        $resolved = [];

        foreach ($variablesConfig as $name => $entry) {
            $resolved[$name] = $this->resolveOne(is_array($entry) ? $entry : [], $contact, $campaign, $multiValueSeparator, $language);
        }

        return $resolved;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function resolveOne(array $entry, Lead $contact, Campaign $campaign, string $multiValueSeparator, string $language): string
    {
        return match ($entry['source'] ?? 'static') {
            'field'         => $this->resolveContactField($contact, (string) ($entry['field'] ?? ''), $language, self::calculationText($entry)),
            'custom_object' => $this->customObjectVariableResolver->resolve(
                $contact,
                $campaign,
                (string) ($entry['customObject'] ?? ''),
                (string) ($entry['customObjectField'] ?? ''),
                $multiValueSeparator,
                $language,
                self::calculationText($entry)
            ),
            default => (string) ($entry['value'] ?? ''),
        };
    }

    /**
     * The number of the Calculation box of a field variable, set on the Email's Variables tab as `dateOffsetDays`
     * (negative subtracts): days for a date, a value for a number. Kept as text ('13', '-0.5'; a comma is read as a
     * point) so no float rounds it. Anything that is not a number, or none, is '0'. Each formatter takes what it
     * needs: a date and an int use the whole part, a decimal uses the places. The ten-year limit of a date is in
     * DateFormatter; a number has no limit.
     *
     * @param array<string, mixed> $entry
     */
    public static function calculationText(array $entry): string
    {
        $amount = $entry['dateOffsetDays'] ?? 0;

        if (!is_int($amount) && !is_string($amount)) {
            return '0';
        }

        $amount = str_replace(',', '.', trim((string) $amount));

        return 1 === preg_match('/^[+-]?\d+(\.\d+)?$/', $amount) ? $amount : '0';
    }

    /**
     * Public: also called directly by CampaignTriggerSubscriber/
     * SmsCampaignTriggerSubscriber to read a fixed contact field (e.g.
     * 'inst_id_lyceum') straight into the dispatch payload, outside of the
     * variablesJson source-picker mechanism this class otherwise serves.
     */
    public function resolveContactField(Lead $contact, string $fieldAlias, string $language = LocaleConventions::DEFAULT_LOCALE, string $calculation = '0'): string
    {
        if ('' === $fieldAlias) {
            return '';
        }

        // Contacts loaded via LeadModel::getEntity() don't have their custom
        // field values hydrated onto the entity by default (only Doctrine's
        // own mapped columns are) — the fields cache getFieldValue() reads
        // from has to be populated explicitly first, same as
        // LeadModel::getEntity()'s own merged-contact fallback branch does.
        if ([] === $contact->getFields()) {
            $contact->setFields($this->leadModel->getRepository()->getFieldValues($contact->getId()));
        }

        $value = $contact->getFieldValue($fieldAlias);

        if (null === $value) {
            return '';
        }

        // Mautic stores date/datetime fields as 'Y-m-d'/'Y-m-d H:i:s' —
        // reformatted to the template's language for dispatch. getField() (not
        // getFieldValue(), which already applied CustomFieldHelper's own
        // type coercion above) is where the field's 'type' actually lives.
        $field = $contact->getField($fieldAlias);
        $type  = is_array($field) ? (string) ($field['type'] ?? '') : '';

        return FieldCalculator::apply((string) $value, $type, $language, $calculation);
    }
}
