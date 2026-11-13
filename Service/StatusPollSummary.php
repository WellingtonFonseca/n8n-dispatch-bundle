<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

use MauticPlugin\N8nDispatchBundle\Entity\DispatchTracking;

/**
 * The status poll's run summary: one text line per channel, written by the command and read back by LastRunReport.
 * The writer and the reader live together, so a change to the line breaks one test, not the screen.
 */
class StatusPollSummary
{
    private const COUNTS = '/^(\w+): asked (\d+), changed (\d+), unchanged (\d+), unknown (\d+), invalid (\d+), (\d+) calls?$/';

    /**
     * @param array{requested: int, changed: int, unchanged: int, unknown: int, invalid: int, calls: int} $counts
     */
    public static function line(string $channel, array $counts): string
    {
        return sprintf(
            '%s: asked %d, changed %d, unchanged %d, unknown %d, invalid %d, %d calls',
            $channel,
            $counts['requested'],
            $counts['changed'],
            $counts['unchanged'],
            $counts['unknown'],
            $counts['invalid'],
            $counts['calls'],
        );
    }

    public static function errorLine(string $channel, string $error): string
    {
        return sprintf('%s: %s', $channel, $error);
    }

    /**
     * "email: asked 1, ..." gives the counts, "sms: <text>" an error, any other line a message.
     *
     * @return array<string, mixed>
     */
    public static function parse(?string $summary): array
    {
        $fields = [];

        foreach (preg_split('/\R/', (string) $summary) ?: [] as $line) {
            $line = trim($line);

            if ('' === $line) {
                continue;
            }

            if (1 === preg_match(self::COUNTS, $line, $m)) {
                $fields[$m[1]] = [
                    'asked'     => (int) $m[2],
                    'changed'   => (int) $m[3],
                    'unchanged' => (int) $m[4],
                    'unknown'   => (int) $m[5],
                    'invalid'   => (int) $m[6],
                    'calls'     => (int) $m[7],
                ];
            } elseif (1 === preg_match('/^(\w+): (.+)$/', $line, $m) && in_array($m[1], DispatchTracking::CHANNELS, true)) {
                $fields[$m[1]] = ['error' => $m[2]];
            } else {
                $fields['message'] = isset($fields['message']) ? $fields['message']."\n".$line : $line;
            }
        }

        return $fields;
    }
}
