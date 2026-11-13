<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

/**
 * The rows of the Mirror tab's log: one per resync run, the summary line of the run split into counts.
 */
class MirrorResyncLogRows
{
    private const COUNTS = ['due', 'calls', 'ok', 'failed', 'skipped', 'left'];

    /**
     * @param list<array{started_at: string, status: string, summary: string|null}> $runs
     *
     * @return list<array{started_at: string, status: string, detail: string, due: ?int, calls: ?int, ok: ?int, failed: ?int, skipped: ?int, left: ?int}>
     */
    public static function build(array $runs): array
    {
        return array_map(static function (array $run): array {
            $fields = MirrorResyncSummary::parse($run['summary']);
            $row    = ['started_at' => $run['started_at'], 'status' => $run['status'], 'detail' => (string) ($fields['error'] ?? $fields['message'] ?? '')];

            foreach (self::COUNTS as $count) {
                $row[$count] = $fields[$count] ?? null;
            }

            return $row;
        }, $runs);
    }
}
