<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Resolver;

/**
 * Custom Object 'decimal' fields (DECIMAL(20,6), see the Custom Objects
 * plugin's CustomFieldType/DecimalType.php) come out of storage with all
 * six places ("1.100000"). Sent as "at least two places, and from the
 * third on only up to the last non-zero one": 1.10, 3.75, 2.00, 1.0001.
 *
 * Works on the stored text, never through float, so no rounding can creep
 * in. The separator stays a dot.
 */
final class DecimalFormatter
{
    private const MIN_PLACES = 2;

    /**
     * Returns $value unchanged for any $type other than 'decimal', or when
     * $value isn't a plain decimal number (empty, text, scientific
     * notation) — same "never throw a dispatchable value away" stance as
     * BrazilianDateFormatter.
     */
    public static function format(string $value, string $type): string
    {
        if ('decimal' !== $type || 1 !== preg_match('/^(-?\d+)(?:\.(\d+))?$/', $value, $parts)) {
            return $value;
        }

        $fraction = str_pad(rtrim($parts[2] ?? '', '0'), self::MIN_PLACES, '0');

        return $parts[1].'.'.$fraction;
    }
}
