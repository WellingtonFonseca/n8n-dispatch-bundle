<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

use MauticPlugin\N8nDispatchBundle\Entity\DispatchTracking;

/**
 * The "Check now" button on the plugin's settings screen: asks n8n about the
 * three channels once, right away, the way a scheduled run would (same
 * limits, the configured webhook) — so a workflow can be built and fixed
 * while the schedule is still off. Works with the switch off; it does not
 * touch the schedule or the "Last run" line, which are about scheduled runs.
 */
class StatusPollNow
{
    private const LIMIT        = 200;
    private const MAX_AGE_DAYS = 7;

    public function __construct(private StatusPoller $poller)
    {
    }

    /**
     * @return array{ok: bool, channels: array<string, array{requested: int, changed: int, unchanged: int, unknown: int, invalid: int, error: ?string}>}
     */
    public function run(\DateTimeImmutable $now): array
    {
        $channels = [];
        $ok       = true;

        foreach (DispatchTracking::CHANNELS as $channel) {
            try {
                $summary = $this->poller->poll($channel, self::LIMIT, self::MAX_AGE_DAYS, DispatchTracking::OUTCOME_PENDING, null, $now);
            } catch (\Throwable $e) {
                $summary = ['requested' => 0, 'changed' => 0, 'unchanged' => 0, 'unknown' => 0, 'invalid' => 0, 'error' => $e->getMessage()];
            }

            $ok &= null === $summary['error'];
            $channels[$channel] = $summary;
        }

        return ['ok' => (bool) $ok, 'channels' => $channels];
    }
}
