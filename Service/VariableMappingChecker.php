<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

/**
 * Decides whether a template variable has been mapped on the Email's "Variables" tab.
 *
 * Every variable found in a template gets a row there with the default (static, blank) source, and that default
 * is saved as-is, so having a key in the saved mapping says nothing: a variable only counts as mapped when its
 * row was actually filled in. Keep the rules identical to isEntrySet() in Assets/js/n8ndispatch-shared.js.
 */
class VariableMappingChecker
{
    public function isEntrySet(mixed $entry): bool
    {
        if (!is_array($entry)) {
            return false;
        }

        $source = $entry['source'] ?? '';
        $source = '' === $source ? 'static' : $source;

        return match ($source) {
            'field'         => '' !== (string) ($entry['field'] ?? ''),
            'custom_object' => '' !== (string) ($entry['customObject'] ?? '') && '' !== (string) ($entry['customObjectField'] ?? ''),
            default         => '' !== trim((string) ($entry['value'] ?? '')),
        };
    }

    /**
     * @param list<string>         $names   variable names found in the template
     * @param array<string, mixed> $mapping the saved variablesJson, decoded
     *
     * @return list<string> the names whose entry is not set, in template order
     */
    public function findUnmapped(array $names, array $mapping): array
    {
        return array_values(array_filter(
            $names,
            fn (string $name): bool => !$this->isEntrySet($mapping[$name] ?? null)
        ));
    }
}
