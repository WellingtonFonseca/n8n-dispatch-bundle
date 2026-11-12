<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Entity\Validation;

use MauticPlugin\N8nDispatchBundle\Service\TemplateVariableScanner;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Shared by the HSM and SMS templates: the numbers of the {{variables}} run from 1 to the highest one with no hole,
 * in any order in the text, {{0}} is refused and the highest number is 50 at most. The Meta template has {{1}} to {{n}}, so a hole or a {{0}} would
 * send a payload the Meta refuses. The SMS follows the same pattern, so both channels have one rule. Without a {{0}},
 * the variables are always an object ({"1":...}) and never a JSON list.
 *
 * Steps aside while a placeholder is not a number (NumericVariables says that first). Used as a callable array in
 * the entity's validator metadata (not a closure: the validator caches that metadata, and closures can't be
 * serialized).
 */
final class SequentialVariables
{
    public const MESSAGE = 'mautic.n8ndispatch.template.error.variables_gap';
    public const ZERO    = 'mautic.n8ndispatch.template.error.variables_zero';
    public const LIMIT   = 'mautic.n8ndispatch.template.error.variables_limit';

    // The same number is in Assets/js/n8ndispatch-shared.js (MAX_VARIABLES). With no hole, the highest number is also
    // how many variables the text has.
    public const MAX_VARIABLES = 50;

    public static function validate(?string $text, ExecutionContextInterface $context): void
    {
        $scanner = new TemplateVariableScanner();

        if ([] !== $scanner->findNonNumeric((string) $text)) {
            return;
        }

        $numbers = array_map('intval', $scanner->extract((string) $text));

        if ([] === $numbers) {
            return;
        }

        if (in_array(0, $numbers, true)) {
            $context->buildViolation(self::ZERO)->addViolation();

            return;
        }

        if (max($numbers) > self::MAX_VARIABLES) {
            $context->buildViolation(self::LIMIT)
                ->setParameter('%max%', (string) self::MAX_VARIABLES)
                ->addViolation();

            return;
        }

        $distinct = array_unique($numbers);
        sort($distinct);

        foreach ($distinct as $position => $number) {
            if ($number !== $position + 1) {
                $context->buildViolation(self::MESSAGE)
                    ->setParameter('%missing%', (string) ($position + 1))
                    ->setParameter('%highest%', (string) max($distinct))
                    ->addViolation();

                return;
            }
        }
    }
}
