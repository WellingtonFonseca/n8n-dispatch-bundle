<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Resolver;

/**
 * Both VariableResolver (contact fields) and CustomObjectVariableResolver
 * (Custom Object fields) read date/datetime values straight out of
 * storage — Mautic's own 'Y-m-d'/'Y-m-d H:i:s' format, ISO-ish but not
 * what n8n/Mirror need. Rewritten to the convention of the language set on
 * the template (see LocaleConventions: 'd/m/Y' for pt_BR, 'm/d/Y' for en).
 * Shared here instead of duplicated in both resolvers.
 */
final class DateFormatter
{
    /**
     * Stored formats tried in order. Mautic's date column is a DATETIME, so a
     * 'date' field comes back as 'Y-m-d 00:00:00' (the bare 'Y-m-d' is kept for
     * contact fields and for storage that returns a plain date).
     */
    private const SOURCE_FORMATS = [
        'date'     => ['Y-m-d', 'Y-m-d H:i:s'],
        'datetime' => ['Y-m-d H:i:s'],
    ];

    /**
     * Returns $value unchanged for any $type other than 'date'/'datetime',
     * or if $value doesn't actually match Mautic's own stored format —
     * safer than throwing away a value dispatch would otherwise have
     * sent, in case storage ever returns something unexpected.
     */
    public static function format(string $value, string $type, string $locale = LocaleConventions::DEFAULT_LOCALE): string
    {
        if ('' === $value || !isset(self::SOURCE_FORMATS[$type])) {
            return $value;
        }

        $date = false;
        foreach (self::SOURCE_FORMATS[$type] as $format) {
            $date = \DateTime::createFromFormat('!'.$format, $value);

            if (false !== $date && $date->format($format) === $value) {
                break;
            }
            $date = false;
        }

        if (false === $date) {
            return $value;
        }

        return $date->format(LocaleConventions::dateFormat($locale, $type));
    }
}
