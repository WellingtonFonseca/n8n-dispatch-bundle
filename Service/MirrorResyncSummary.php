<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

/**
 * The Mirror resync's run summary: one text line, written by the command and read back by LastRunReport.
 */
class MirrorResyncSummary
{
    private const LINE = '/^due (\d+), calls (\d+), ok (\d+), failed (\d+), skipped (\d+), left (\d+)(?:; error: (.+))?$/s';

    /**
     * @param array{due: int, calls: int, ok: int, failed: int, skipped: int, left: int, error: ?string} $summary
     */
    public static function line(array $summary): string
    {
        $line = sprintf('due %d, calls %d, ok %d, failed %d, skipped %d, left %d', $summary['due'], $summary['calls'], $summary['ok'], $summary['failed'], $summary['skipped'], $summary['left']);

        return null === $summary['error'] ? $line : $line.'; error: '.$summary['error'];
    }

    /**
     * A line that is not a summary (the text of an exception) becomes a message.
     *
     * @return array<string, mixed>
     */
    public static function parse(?string $summary): array
    {
        $text = trim((string) $summary);

        if ('' === $text) {
            return [];
        }

        if (1 !== preg_match(self::LINE, $text, $m)) {
            return ['message' => $text];
        }

        $fields = [
            'due'     => (int) $m[1],
            'calls'   => (int) $m[2],
            'ok'      => (int) $m[3],
            'failed'  => (int) $m[4],
            'skipped' => (int) $m[5],
            'left'    => (int) $m[6],
        ];

        if (isset($m[7])) {
            $fields['error'] = $m[7];
        }

        return $fields;
    }
}
