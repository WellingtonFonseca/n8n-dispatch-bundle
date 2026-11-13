<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

use MauticPlugin\N8nDispatchBundle\Entity\PollRun;

/**
 * The JSON of the read-only "Last run" block on the Features tab, for any kind of run: when it started, how it
 * ended, a warning when the cron seems stopped, and the fields of the run's summary (each kind has its own summary class).
 */
class LastRunReport
{
    public static function json(string $kind, \DateTimeInterface $startedAt, string $statusLabel, ?string $summary, ?string $warning): string
    {
        $block = [
            'date'   => \DateTimeImmutable::createFromInterface($startedAt)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i').' UTC',
            'status' => $statusLabel,
        ];

        if (null !== $warning && '' !== $warning) {
            $block['warning'] = $warning;
        }

        $fields = PollRun::KIND_MIRROR_RESYNC === $kind ? MirrorResyncSummary::parse($summary) : StatusPollSummary::parse($summary);

        return (string) json_encode($block + $fields, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
