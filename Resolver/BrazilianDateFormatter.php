<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Resolver;

/**
 * Both VariableResolver (contact fields) and CustomObjectVariableResolver
 * (Custom Object fields) read date/datetime values straight out of
 * storage — Mautic's own 'Y-m-d'/'Y-m-d H:i:s' format, ISO-ish but not
 * what n8n/Mirror need: the client's templates expect pt-BR dates
 * ('d/m/Y'). Shared here instead of duplicated in both resolvers.
 */
final class BrazilianDateFormatter
{
    private const SOURCE_FORMAT = [
        'date'     => 'Y-m-d',
        'datetime' => 'Y-m-d H:i:s',
    ];

    private const TARGET_FORMAT = [
        'date'     => 'd/m/Y',
        'datetime' => 'd/m/Y H:i:s',
    ];

    /**
     * Returns $value unchanged for any $type other than 'date'/'datetime',
     * or if $value doesn't actually match Mautic's own stored format —
     * safer than throwing away a value dispatch would otherwise have
     * sent, in case storage ever returns something unexpected.
     */
    public static function format(string $value, string $type): string
    {
        if ('' === $value || !isset(self::SOURCE_FORMAT[$type])) {
            return $value;
        }

        $date = \DateTime::createFromFormat(self::SOURCE_FORMAT[$type], $value);

        if (false === $date) {
            return $value;
        }

        return $date->format(self::TARGET_FORMAT[$type]);
    }
}
