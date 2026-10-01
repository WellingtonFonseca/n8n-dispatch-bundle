<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Entity\Validation;

use MauticPlugin\N8nDispatchBundle\Service\TemplateVariableScanner;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Shared by the HSM and SMS templates: their {{variables}} are positional, {{1}}, {{2}}, ..., nothing else.
 * Used as a callable array in each entity's validator metadata (not a closure: the validator caches that
 * metadata, and closures can't be serialized).
 *
 * One generic message however many placeholders are wrong: it explains the pattern, it doesn't list the culprits.
 */
final class NumericVariables
{
    public const MESSAGE = 'mautic.n8ndispatch.template.error.numeric_variables';

    public static function validate(?string $text, ExecutionContextInterface $context): void
    {
        if ([] === (new TemplateVariableScanner())->findNonNumeric((string) $text)) {
            return;
        }

        $context->buildViolation(self::MESSAGE)->addViolation();
    }
}
