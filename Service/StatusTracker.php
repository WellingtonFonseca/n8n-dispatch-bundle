<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

use MauticPlugin\N8nDispatchBundle\Entity\DispatchCallback;
use MauticPlugin\N8nDispatchBundle\Entity\DispatchCallbackRepository;
use MauticPlugin\N8nDispatchBundle\Entity\DispatchTracking;
use MauticPlugin\N8nDispatchBundle\Entity\DispatchTrackingRepository;

/**
 * The bookkeeping for "which dispatches does n8n still owe an answer for":
 * registers the ids of a successful dispatch, hands the poll its queue,
 * applies the answers, and builds what the Timeline card shows. See
 * Entity/DispatchTracking.php and Entity/DispatchCallback.php.
 */
class StatusTracker
{
    public function __construct(
        private DispatchTrackingRepository $trackings,
        private DispatchCallbackRepository $callbacks,
    ) {
    }

    /**
     * Starts tracking the ids n8n returned for one dispatch, all 'pending'.
     * An empty id is skipped and an id already tracked is left alone, so
     * calling this twice for the same dispatch is harmless.
     *
     * $now is the dispatch date the rows are given (the moment of the dispatch,
     * or, for the backfill, when it was really made). Returns how many rows
     * were created.
     *
     * @param array<string, int|string|null> $refs refType => id value
     */
    public function register(string $channel, array $refs, ?\DateTimeImmutable $now = null): int
    {
        $now      = $now ?? new \DateTimeImmutable();
        $groupKey = bin2hex(random_bytes(8));
        $created  = 0;

        foreach ($refs as $refType => $value) {
            if (null === $value || '' === (string) $value) {
                continue;
            }

            if (null !== $this->trackings->findOneByRef($refType, (string) $value)) {
                continue;
            }

            $tracking = DispatchTracking::create($channel, $refType, (string) $value, $groupKey, $now);
            $this->trackings->saveEntity($tracking);

            // The first entry of every history: the dispatch itself, waiting for its answer.
            $this->callbacks->saveEntity(DispatchCallback::create((int) $tracking->getId(), DispatchTracking::OUTCOME_PENDING, null, [], $now));
            ++$created;
        }

        return $created;
    }

    /**
     * Drops the tracking rows of a dispatch (found by the ids in the log's
     * metadata) and their history, when a resend replaces that dispatch.
     *
     * @param array<string, mixed> $metadata
     */
    public function forget(array $metadata): void
    {
        $rows = $this->trackings->findByRefs($this->refsIn($metadata));

        if ([] === $rows) {
            return;
        }

        $this->callbacks->deleteEntities($this->callbacks->findByTrackingIds(array_map(fn (DispatchTracking $t): int => (int) $t->getId(), $rows)));
        $this->trackings->deleteEntities($rows);
    }

    /**
     * Puts a note on a dispatch still waiting for its answer: on the row
     * (what the card shows while it waits) and on its first history entry
     * (what stays once the answer replaces the row's message).
     *
     * @param array<string, mixed> $metadata
     */
    public function annotate(array $metadata, string $message): void
    {
        $rows = $this->trackings->findByRefs($this->refsIn($metadata));

        if ([] === $rows) {
            return;
        }

        $first = [];
        foreach ($this->callbacks->findByTrackingIds(array_map(fn (DispatchTracking $t): int => (int) $t->getId(), $rows)) as $callback) {
            $first[$callback->getTrackingId()] ??= $callback;
        }

        foreach ($rows as $row) {
            $row->setMessage($message);
            $this->trackings->saveEntity($row);

            if (isset($first[(int) $row->getId()])) {
                $first[(int) $row->getId()]->setMessage($message);
                $this->callbacks->saveEntity($first[(int) $row->getId()]);
            }
        }
    }

    public function isTracked(string $refType, string $value): bool
    {
        return null !== $this->trackings->findOneByRef($refType, $value);
    }

    /**
     * One batch of the queue: up to $limit rows with an id after $afterId.
     *
     * @return list<DispatchTracking>
     */
    public function pending(string $channel, string $outcome, int $limit, int $maxAgeDays, \DateTimeImmutable $now, int $afterId = 0): array
    {
        return $this->trackings->findPending($channel, $outcome, $now->modify('-'.$maxAgeDays.' days'), $limit, $afterId);
    }

    /**
     * Applies parsed answers (see StatusResponseParser). An id that is not
     * tracked is only counted. A known id always gets its check recorded;
     * only an outcome different from the stored one adds a history row.
     *
     * @param list<array{refType: string, refValue: string, outcome: string, message: ?string, body: array<string, mixed>}> $items
     *
     * @return array{changed: int, unchanged: int, unknown: int}
     */
    public function apply(array $items, \DateTimeImmutable $now): array
    {
        $summary = ['changed' => 0, 'unchanged' => 0, 'unknown' => 0];

        foreach ($items as $item) {
            $tracking = $this->trackings->findOneByRef($item['refType'], $item['refValue']);

            if (null === $tracking) {
                ++$summary['unknown'];

                continue;
            }

            $changed = $tracking->applyOutcome($item['outcome'], $item['message'], $now);
            $this->trackings->saveEntity($tracking);

            if (!$changed) {
                ++$summary['unchanged'];

                continue;
            }

            $this->callbacks->saveEntity(DispatchCallback::create((int) $tracking->getId(), $item['outcome'], $item['message'], $item['body'], $now));
            ++$summary['changed'];
        }

        return $summary;
    }

    /**
     * What the Timeline card shows for one campaign log: a row per tracked
     * id found in the log's metadata, each with its history. Empty when the
     * log has no ids (test/paused dispatches, failed dispatches, rows from
     * before this feature).
     *
     * @param array<string, mixed> $metadata
     *
     * @return list<array{refType: string, refValue: string, outcome: string, message: ?string, checkCount: int, lastCheckedAt: ?\DateTimeImmutable, changedAt: ?\DateTimeImmutable, history: list<array{outcome: string, message: ?string, receivedAt: \DateTimeImmutable}>}>
     */
    public function viewForMetadata(array $metadata): array
    {
        $refs = $this->refsIn($metadata);

        if ([] === $refs) {
            return [];
        }

        $rows = $this->trackings->findByRefs($refs);

        if ([] === $rows) {
            return [];
        }

        $historyByTracking = [];
        foreach ($this->callbacks->findByTrackingIds(array_map(fn (DispatchTracking $t): int => (int) $t->getId(), $rows)) as $callback) {
            $historyByTracking[$callback->getTrackingId()][] = [
                'outcome'    => $callback->getOutcome(),
                'message'    => $callback->getMessage(),
                'receivedAt' => $callback->getReceivedAt(),
            ];
        }

        $view = [];
        foreach ($rows as $row) {
            $view[] = [
                'refType'       => $row->getRefType(),
                'refValue'      => $row->getRefValue(),
                'outcome'       => $row->getOutcome(),
                'message'       => $row->getMessage(),
                'checkCount'    => $row->getCheckCount(),
                'lastCheckedAt' => $row->getLastCheckedAt(),
                'changedAt'     => $row->getChangedAt(),
                'history'       => $historyByTracking[$row->getId()] ?? [],
            ];
        }

        return $view;
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @return array<string, string> refType => id found in the log's metadata
     */
    private function refsIn(array $metadata): array
    {
        $refs = [];

        foreach (DispatchTracking::REFS_BY_CHANNEL as $refTypes) {
            foreach ($refTypes as $refType) {
                $value = $metadata[$refType] ?? null;

                if ((is_int($value) || is_string($value)) && '' !== (string) $value) {
                    $refs[$refType] = (string) $value;
                }
            }
        }

        return $refs;
    }
}
