<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

use MauticPlugin\N8nDispatchBundle\UnsubscribeVariable;

/**
 * Extracts {{variable}} placeholder names from template HTML/text. Shared
 * by Controller/AjaxController.php (campaign builder form fields) and
 * EventListener/EmailTabSubscriber.php (the "Variables" tab on the native
 * Email edit page), so the two surfaces can never drift on what counts as
 * a variable.
 */
class TemplateVariableScanner
{
    /**
     * Every template variable must start with this prefix, enforced when an Email is saved
     * (Form/Extension/EmailVariablePrefixExtension.php).
     */
    public const REQUIRED_PREFIX = 'n8n_';

    /**
     * Any {{ ... }} placeholder, whatever is inside it (unlike VARIABLE_PATTERN, which only recognizes names).
     */
    private const PLACEHOLDER_PATTERN = '/\{\{\s*([^{}]*?)\s*\}\}/';

    private const VARIABLE_PATTERN = '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/';

    /**
     * @return list<string>
     */
    public function extract(string $html): array
    {
        preg_match_all(self::VARIABLE_PATTERN, $html, $matches);

        return array_values(array_diff(array_unique($matches[1]), [UnsubscribeVariable::KEY]));
    }

    /**
     * The variables from extract() that don't start with REQUIRED_PREFIX.
     * The reserved unsubscribe variable is never listed, extract() already drops it.
     *
     * @return list<string>
     */
    public function extractWithoutPrefix(string $html): array
    {
        return array_values(array_filter(
            $this->extract($html),
            static fn (string $name): bool => !str_starts_with($name, self::REQUIRED_PREFIX)
        ));
    }

    /**
     * The {{ ... }} placeholders whose content is not only digits, as written, without duplicates. An HSM template
     * (WhatsApp) only accepts positional placeholders, {{1}}, {{2}}, ...; anything else, empty ones included,
     * is listed here.
     *
     * @return list<string>
     */
    public function findNonNumeric(string $text): array
    {
        preg_match_all(self::PLACEHOLDER_PATTERN, $text, $matches);

        return array_values(array_unique(array_filter(
            $matches[1],
            static fn (string $content): bool => 1 !== preg_match('/^\d+$/', $content)
        )));
    }
}
