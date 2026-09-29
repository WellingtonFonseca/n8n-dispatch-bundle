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
    private const VARIABLE_PATTERN = '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/';

    /**
     * @return list<string>
     */
    public function extract(string $html): array
    {
        preg_match_all(self::VARIABLE_PATTERN, $html, $matches);

        return array_values(array_diff(array_unique($matches[1]), [UnsubscribeVariable::KEY]));
    }
}
