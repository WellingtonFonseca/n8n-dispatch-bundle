<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

use MauticPlugin\N8nDispatchBundle\Entity\MirrorSyncState;
use MauticPlugin\N8nDispatchBundle\Entity\MirrorSyncStateRepository;

/**
 * One run of the Mirror resync: picks the Emails that are out of sync and sends them again, one call at a time with
 * a pause between calls. It never saves an Email: it reads the columns and sends them (MirrorSync).
 *
 * Order of a run (the batch cuts the list, the rest waits for the next run):
 * 1 last sync failed (fewer than MAX_ATTEMPTS failures in a row), 2 never synced, 3 content changed since the last
 * sync, 4 last attempt older than the refresh hours, oldest first.
 */
class MirrorResync
{
    /** After this many failures in a row an Email waits for the refresh (once a day by default), so it cannot hold the queue. */
    public const MAX_ATTEMPTS = 10;

    private const PRIORITY_FAILED  = 1;
    private const PRIORITY_NEVER   = 2;
    private const PRIORITY_CHANGED = 3;
    private const PRIORITY_REFRESH = 4;

    /** A run stops taking Emails after this: it must end well before PollRunRepository::RUNNING_STALE_MINUTES. */
    public const MAX_RUN_SECONDS = 1800;

    private const PAGE_SIZE = 100;

    private const NOT_CONFIGURED = 'the integration is off or has no webhook URL';

    private const NOTHING_SENT = ['due' => 0, 'calls' => 0, 'ok' => 0, 'failed' => 0, 'skipped' => 0, 'left' => 0, 'error' => null];

    public function __construct(
        private MirrorSyncStateRepository $states,
        private MirrorSync $mirrorSync,
        private MirrorSyncPayload $payload,
        private CallPacer $pacer,
    ) {
    }

    /**
     * @return array{due: int, calls: int, ok: int, failed: int, skipped: int, left: int, error: ?string}
     */
    public function run(int $batch, int $delayMs, int $refreshHours, \DateTimeImmutable $now, float $maxSeconds = self::MAX_RUN_SECONDS): array
    {
        if (!$this->mirrorSync->isConfigured()) {
            return ['error' => self::NOT_CONFIGURED] + self::NOTHING_SENT;
        }

        $this->states->pruneOrphans();
        $due      = $this->dueEmailIds($now, $refreshHours);
        $selected = array_slice($due, 0, max(1, $batch));

        $sent = $this->send($selected, $delayMs, microtime(true) + $maxSeconds);
        $sent['left'] += count($due) - count($selected);

        return ['due' => count($due)] + $sent;
    }

    /**
     * Sends one Email whatever its state (the command's --email-id).
     *
     * @return array{due: int, calls: int, ok: int, failed: int, skipped: int, left: int, error: ?string}
     */
    public function runOne(int $emailId): array
    {
        if (!$this->mirrorSync->isConfigured()) {
            return ['error' => self::NOT_CONFIGURED] + self::NOTHING_SENT;
        }

        if (null === $this->states->emailRow($emailId)) {
            return ['error' => 'email '.$emailId.' not found'] + self::NOTHING_SENT;
        }

        return ['due' => 1] + $this->send([$emailId], 0, microtime(true) + self::MAX_RUN_SECONDS);
    }

    /**
     * The ids to send, most urgent first.
     *
     * @return list<int>
     */
    public function dueEmailIds(\DateTimeImmutable $now, int $refreshHours): array
    {
        $due     = [];
        $afterId = 0;
        $limit   = $now->modify('-'.$refreshHours.' hours')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        do {
            $rows = $this->states->page($afterId, self::PAGE_SIZE);

            foreach ($rows as $row) {
                $afterId  = (int) $row['id'];
                $priority = $this->priority($row, $limit);

                if (null !== $priority) {
                    // Inside a priority the oldest attempt goes first; the id breaks ties.
                    $due[] = [$priority, (string) $row['attemptedAt'], $afterId];
                }
            }
        } while (count($rows) === self::PAGE_SIZE);

        usort($due, static fn (array $a, array $b): int => $a <=> $b);

        return array_map(static fn (array $entry): int => $entry[2], $due);
    }

    /**
     * @param array<string, mixed> $row      a row of MirrorSyncStateRepository::page()
     * @param string               $limitUtc a sync before this date is due for the refresh
     */
    private function priority(array $row, string $limitUtc): ?int
    {
        $status = $row['status'] ?? null;

        if (null === $status) {
            return self::PRIORITY_NEVER;
        }

        if (MirrorSyncState::STATUS_FAILED === $status && (int) $row['attempts'] < self::MAX_ATTEMPTS) {
            return self::PRIORITY_FAILED;
        }

        if ($row['contentHash'] !== $this->payload->contentHash($this->fieldsOfRow($row))) {
            return self::PRIORITY_CHANGED;
        }

        if (MirrorSyncState::STATUS_SKIPPED === $status) {
            return null;
        }

        // An ok sync has attempted_at = synced_at; a failed one past MAX_ATTEMPTS counts from its last attempt.
        return (string) $row['attemptedAt'] < $limitUtc ? self::PRIORITY_REFRESH : null;
    }

    /**
     * @param list<int> $emailIds
     * @param float     $deadline microtime after which no new Email is taken; the rest counts as left
     *
     * @return array{due: int, calls: int, ok: int, failed: int, skipped: int, left: int, error: ?string}
     */
    private function send(array $emailIds, int $delayMs, float $deadline): array
    {
        $counts = self::NOTHING_SENT;

        foreach ($emailIds as $index => $emailId) {
            if (microtime(true) >= $deadline) {
                $counts['left'] = count($emailIds) - $index;

                return $counts;
            }

            // Read again right before the send: a save made while the job ran is never overwritten by older content.
            $row = $this->states->emailRow($emailId);

            if (null === $row) {
                continue;
            }

            $fields = $this->fieldsOfRow($row);

            if ($this->payload->hasContent($fields)) {
                $this->pacer->pause($delayMs);
                ++$counts['calls'];
            }

            $status = $this->mirrorSync->sync($emailId, $fields, null, MirrorSyncState::SOURCE_JOB, new \DateTimeImmutable());

            if (null === $status) {
                return ['error' => 'the integration was turned off during the run', 'left' => count($emailIds) - $index] + $counts;
            }

            ++$counts[$status];
        }

        return $counts;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, ?string>
     */
    private function fieldsOfRow(array $row): array
    {
        $fields = [];

        foreach (['subject', 'customHtml', 'preheaderText', 'fromName', 'fromAddress'] as $key) {
            $fields[$key] = isset($row[$key]) ? (string) $row[$key] : null;
        }

        return $fields;
    }
}
