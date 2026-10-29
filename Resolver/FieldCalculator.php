<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Resolver;

/**
 * The Calculation of the Email's Variables tab on one stored value, for a contact field and for a Custom Object
 * field alike: a date moves by the whole part of $calculation (DateFormatter), a number gets it added
 * (NumberCalculator). Each one ignores the other's types, so the type picks the path and any other type is
 * returned as it is. $calculation is the text of the box, see VariableResolver::addDays().
 */
final class FieldCalculator
{
    public static function apply(string $value, string $type, string $locale, string $calculation): string
    {
        return NumberCalculator::add(DateFormatter::format($value, $type, $locale, (int) $calculation), $type, $calculation);
    }
}
