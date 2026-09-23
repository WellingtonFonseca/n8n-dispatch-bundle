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
     * @param array<string, array<string, mixed>> $variablesConfig
     *
     * @return array<string, string>
     */
    public function resolveAll(array $variablesConfig, Lead $contact, Campaign $campaign): array
    {
        $resolved = [];

        foreach ($variablesConfig as $name => $entry) {
            $resolved[$name] = $this->resolveOne(is_array($entry) ? $entry : [], $contact, $campaign);
        }

        return $resolved;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function resolveOne(array $entry, Lead $contact, Campaign $campaign): string
    {
        return match ($entry['source'] ?? 'static') {
            'field'         => $this->resolveContactField($contact, (string) ($entry['field'] ?? '')),
            'custom_object' => $this->customObjectVariableResolver->resolve(
                $contact,
                $campaign,
                (string) ($entry['customObject'] ?? ''),
                (string) ($entry['customObjectField'] ?? '')
            ),
            default => (string) ($entry['value'] ?? ''),
        };
    }

    /**
     * Public: also called directly by CampaignTriggerSubscriber/
     * SmsCampaignTriggerSubscriber to read a fixed contact field (e.g.
     * 'inst_id_lyceum') straight into the dispatch payload, outside of the
     * variablesJson source-picker mechanism this class otherwise serves.
     */
    public function resolveContactField(Lead $contact, string $fieldAlias): string
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
        // reformatted to pt-BR for dispatch, on request. getField() (not
        // getFieldValue(), which already applied CustomFieldHelper's own
        // type coercion above) is where the field's 'type' actually lives.
        $field = $contact->getField($fieldAlias);
        $type  = is_array($field) ? (string) ($field['type'] ?? '') : '';

        return BrazilianDateFormatter::format((string) $value, $type);
    }
}
