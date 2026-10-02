<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Resolver;

/**
 * How dates and decimal numbers are written per language, for
 * DateFormatter and DecimalFormatter. The language is the one picked on
 * the Email / SMS Template / HSM Template editor (Mautic locale codes,
 * 'pt_BR', 'en', 'en_GB'...).
 *
 * Lookup: the exact code, then its language part ('en_US' -> 'en'), then
 * the default. Anything not listed gets the default (pt_BR), which is also
 * what every dispatch did before the language existed.
 */
final class LocaleConventions
{
    public const DEFAULT_LOCALE = 'pt_BR';

    private const CONVENTIONS = [
        'pt_BR' => ['date' => 'd/m/Y', 'decimalSeparator' => ','],
        'en'    => ['date' => 'm/d/Y', 'decimalSeparator' => '.'],
        'en_GB' => ['date' => 'd/m/Y', 'decimalSeparator' => '.'],
    ];

    public static function dateFormat(string $locale, string $type): string
    {
        $date = self::lookup($locale)['date'];

        return match ($type) {
            'datetime'         => $date.' H:i:s',
            // no seconds: the Timeline card's callback dates (StatusExtension)
            'datetime_minutes' => $date.' H:i',
            default            => $date,
        };
    }

    public static function decimalSeparator(string $locale): string
    {
        return self::lookup($locale)['decimalSeparator'];
    }

    /**
     * @return array{date: string, decimalSeparator: string}
     */
    private static function lookup(string $locale): array
    {
        return self::CONVENTIONS[$locale]
            ?? self::CONVENTIONS[explode('_', $locale)[0]]
            ?? self::CONVENTIONS[self::DEFAULT_LOCALE];
    }
}
