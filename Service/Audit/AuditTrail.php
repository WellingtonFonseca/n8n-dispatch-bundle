<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service\Audit;

/**
 * (A copy of the one in MauticPatchesBundle, on purpose: the plugins do not depend on each other.)
 *
 * The rows of an "Histórico" tab, made from the log lines core keeps in its audit_log table (AuditLogModel::getLogForObject()).
 * Core writes `details` as {field: [old, new]} for a create or update, and as {name: ...} for a delete; it shows none of it on a
 * page, so this turns each line into a date, a user, an action and a list of changes a table can print.
 */
final class AuditTrail
{
    /** A long value (an email's HTML) is cut: the tab shows what changed, not the whole body. */
    private const MAX_VALUE_LENGTH = 300;

    /**
     * @param list<array<string, mixed>> $logs      lines as the repository returns them, newest first
     * @param string                     $object    the audit_log object of the template kind, for the labels
     * @param AuditFieldFormatter        $formatter says the fields and their values in the language of the reader
     *
     * @return list<array{date: mixed, user: string, action: string, changes: list<array{field: string, old: string, new: string}>}>
     */
    public static function rows(array $logs, string $object, AuditFieldFormatter $formatter): array
    {
        $rows = [];

        foreach ($logs as $log) {
            $rows[] = [
                'date'    => $log['dateAdded'] ?? null,
                'user'    => (string) ($log['userName'] ?? ''),
                'action'  => (string) ($log['action'] ?? ''),
                'changes' => self::changes($object, $formatter, is_array($log['details'] ?? null) ? $log['details'] : []),
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $details
     *
     * @return list<array{field: string, old: string, new: string}>
     */
    private static function changes(string $object, AuditFieldFormatter $formatter, array $details): array
    {
        $changes = [];

        foreach ($details as $field => $values) {
            // A delete keeps only the name; any other shape that is not [old, new] is shown as the new value.
            $pair      = is_array($values) && array_key_exists(0, $values) && array_key_exists(1, $values);
            $changes[] = [
                'field' => $formatter->label($object, (string) $field),
                'old'   => $pair ? $formatter->value($object, (string) $field, self::text($values[0])) : '',
                'new'   => $formatter->value($object, (string) $field, $pair ? self::text($values[1]) : self::text($values)),
            ];
        }

        return $changes;
    }

    private static function text(mixed $value): string
    {
        if (null === $value) {
            return '';
        }

        // A flag is "1" or "0": false must not read as an empty value (the formatter turns a flag into its word).
        $text = is_bool($value) ? ($value ? '1' : '0') : (is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return mb_strlen($text) > self::MAX_VALUE_LENGTH ? mb_substr($text, 0, self::MAX_VALUE_LENGTH).'…' : $text;
    }
}
