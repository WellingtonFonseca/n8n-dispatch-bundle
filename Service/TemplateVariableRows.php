<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

/**
 * The variable sources of a template ('variablesJson': {"1": {"source": "field", "value": "firstname"}, ...}, the shape
 * Form/Type/EmailDispatchActionType.php documents) as plain rows for the read-only details modal of the SMS and HSM templates.
 */
class TemplateVariableRows
{
    /**
     * @return list<array{name: string, source: string, value: string}> source is 'static', 'field' or 'custom_object'
     */
    public static function fromJson(?string $json): array
    {
        $data = json_decode((string) $json, true);

        if (!is_array($data)) {
            return [];
        }

        $rows = [];

        foreach ($data as $name => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $source = in_array($entry['source'] ?? null, ['field', 'custom_object'], true) ? $entry['source'] : 'static';

            $rows[] = [
                'name'   => (string) $name,
                'source' => $source,
                'value'  => match ($source) {
                    'field'         => (string) ($entry['field'] ?? $entry['value'] ?? ''),
                    'custom_object' => trim(($entry['customObject'] ?? '').' / '.($entry['customObjectField'] ?? ''), ' /'),
                    default         => (string) ($entry['value'] ?? ''),
                },
            ];
        }

        return $rows;
    }
}
