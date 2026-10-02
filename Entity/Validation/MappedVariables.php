<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Entity\Validation;

use MauticPlugin\N8nDispatchBundle\Service\TemplateVariableScanner;
use MauticPlugin\N8nDispatchBundle\Service\VariableMappingChecker;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Shared by the HSM and SMS templates: every {{variable}} in the text needs a filled-in source on the template's own
 * variable rows (the same rule the Email's "Variables N8N" tab follows, see VariableMappingChecker). Unlike the
 * Email, the rows are on the same form as the text, so this applies on every save, new or existing.
 *
 * Used as a callable array in each entity's validator metadata (not a closure: the validator caches that metadata,
 * and closures can't be serialized). It reads variablesJson from the validated entity.
 *
 * Steps aside while the text still has non-numeric placeholders (NumericVariables): fix the names first, mapping
 * them would only add a second message about the same placeholders.
 */
final class MappedVariables
{
    public const MESSAGE = 'mautic.n8ndispatch.template.error.unmapped_variables';

    public static function validate(?string $text, ExecutionContextInterface $context): void
    {
        $scanner = new TemplateVariableScanner();

        if ([] !== $scanner->findNonNumeric((string) $text)) {
            return;
        }

        $names = $scanner->extract((string) $text);

        if ([] === $names) {
            return;
        }

        $template = $context->getObject();
        $mapping  = json_decode(is_object($template) && method_exists($template, 'getVariablesJson') ? (string) $template->getVariablesJson() : '', true);

        if ([] === (new VariableMappingChecker())->findUnmapped($names, is_array($mapping) ? $mapping : [])) {
            return;
        }

        $context->buildViolation(self::MESSAGE)->addViolation();
    }
}
