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
     * $campaignLogId (the contact's History entry, when known) ties the rows
     * and their history to that log, so the history survives a resend.
     *
     * @param array<string, int|string|null> $refs refType => id value
     */
    public function register(string $channel, array $refs, ?\DateTimeImmutable $now = null, ?int $campaignLogId = null): int
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

            $tracking = DispatchTracking::create($channel, $refType, (string) $value, $groupKey, $now, $campaignLogId);
            $this->trackings->saveEntity($tracking);

            // The first entry of every history: the dispatch itself, waiting for its answer.
            $this->callbacks->saveEntity(DispatchCallback::create((int) $tracking->getId(), DispatchTracking::OUTCOME_PENDING, null, [], $now)->withLog($campaignLogId, $refType, (string) $value));
            ++$created;
        }

        return $created;
    }

    /**
     * Drops the tracking rows of a dispatch (found by the ids in the log's
     * metadata), when a resend replaces that dispatch, so the poll stops
     * asking about ids that no longer belong to it. Their history is kept:
     * an entry from before the link to the log existed is tied to
     * $campaignLogId first, so it still shows up in that log's history.
     *
     * @param array<string, mixed> $metadata
     */
    public function forget(array $metadata, ?int $campaignLogId = null): void
    {
        $rows = $this->trackings->findByRefs($this->refsIn($metadata));

        if ([] === $rows) {
            return;
        }

        if (null !== $campaignLogId) {
            $this->linkHistory($rows, $campaignLogId);
        }

        $this->trackings->deleteEntities($rows);
    }

    /**
     * Writes one 'dispatch' entry: an attempt to send to n8n, accepted or
     * refused, in the history of the campaign log. The id shown is the first
     * one n8n returned (the Mirror's, for HSM). $response is n8n's answer
     * (decoded JSON, or text) and $resend, for a resend, says who and when
     * (['por' => ..., 'em' => ...]); nothing else is kept. Without a log id
     * there is nothing to attach it to, so nothing is written.
     *
     * @param array<string, int|string|null> $refs   refType => id value
     * @param array<string, string>|null     $resend
     */
    public function recordDispatch(?int $campaignLogId, bool $accepted, array $refs, mixed $response, ?\DateTimeImmutable $now = null, ?array $resend = null): void
    {
        if (null === $campaignLogId) {
            return;
        }

        $refType  = null;
        $refValue = null;

        foreach ($refs as $type => $value) {
            if (null !== $value && '' !== (string) $value) {
                $refType  = $type;
                $refValue = (string) $value;

                break;
            }
        }

        $this->callbacks->saveEntity(DispatchCallback::createDispatch($campaignLogId, $accepted, $refType, $refValue, $response, $now ?? new \DateTimeImmutable(), $resend));
    }

    /**
     * Writes the 'dispatch' entry of a step in 'test' or 'paused' mode: nothing
     * was sent to n8n, and the History says so. Any other mode writes nothing.
     */
    public function recordSimulated(?int $campaignLogId, string $mode, ?\DateTimeImmutable $now = null): void
    {
        if (null === $campaignLogId || !in_array($mode, ['test', 'paused'], true)) {
            return;
        }

        $this->callbacks->saveEntity(DispatchCallback::createSimulated($campaignLogId, $mode, $now ?? new \DateTimeImmutable()));
    }

    /**
     * Deletes the whole history: every tracking row and every entry. The
     * backfill's --reset, to rebuild it from the campaign logs.
     */
    public function reset(): void
    {
        $this->callbacks->deleteAll();
        $this->trackings->deleteAll();
    }

    /**
     * Ties a dispatch made before the link existed to its campaign log: the
     * tracking rows and their history entries get the log id, and the
     * 'dispatch' entry it never had is created, dated $dispatchedAt: what the
     * log says of the call ($mode 'production' with $accepted and n8n's
     * $response, or a 'test' / 'paused' step that sent nothing). Harmless to repeat.
     *
     * @param array<string, int|string|null> $refs refType => id value
     */
    public function adopt(int $campaignLogId, array $refs, \DateTimeImmutable $dispatchedAt, string $mode = 'production', bool $accepted = true, mixed $response = null): void
    {
        $rows = $this->trackings->findByRefs(array_map('strval', array_filter($refs, fn ($v): bool => null !== $v && '' !== (string) $v)));

        foreach ($rows as $row) {
            if (null === $row->getCampaignLogId()) {
                $row->setCampaignLogId($campaignLogId);
                $this->trackings->saveEntity($row);
            }
        }

        if ([] !== $rows) {
            $this->linkHistory($rows, $campaignLogId);
        }

        foreach ($this->callbacks->findByCampaignLogId($campaignLogId) as $entry) {
            if (DispatchCallback::KIND_DISPATCH === $entry->getKind()) {
                return;
            }
        }

        if ('production' === $mode) {
            $this->recordDispatch($campaignLogId, $accepted, $refs, $response, $dispatchedAt);
        } else {
            $this->recordSimulated($campaignLogId, $mode, $dispatchedAt);
        }
    }

    /**
     * The whole history of one campaign log, newest first: every attempt to
     * send ('dispatch') and every change of outcome n8n reported ('callback').
     *
     * @return list<array{kind: string, outcome: string, refType: ?string, refValue: ?string, message: mixed, response: mixed, resend: ?array<string, string>, receivedAt: \DateTimeImmutable}>
     */
    public function historyForLog(int $campaignLogId): array
    {
        return array_map(fn (DispatchCallback $entry): array => [
            'kind'       => $entry->getKind(),
            'outcome'    => $entry->getOutcome(),
            'refType'    => $entry->getRefType(),
            'refValue'   => $entry->getRefValue(),
            'message'    => $entry->getMessage(),
            'response'   => $entry->getResponse(),
            'resend'     => $entry->getResend(),
            'receivedAt' => $entry->getReceivedAt(),
        ], $this->callbacks->findByCampaignLogId($campaignLogId));
    }

    /**
     * Puts a note on a dispatch still waiting for its answer: on the row
     * (what the card shows while it waits) and on its first history entry
     * (what stays once the answer replaces the row's message).
     *
     * The row keeps the message as text, the history entry as given.
     *
     * @param array<string, mixed> $metadata
     */
    public function annotate(array $metadata, mixed $message): void
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
            $row->setMessage(is_string($message) ? $message : (string) json_encode($message, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
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
     * @param list<array{refType: string, refValue: string, outcome: string, message: mixed, body: array<string, mixed>}> $items
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

            // The row keeps the message as text (the card's top block); the history keeps it as n8n sent it.
            $changed = $tracking->applyOutcome($item['outcome'], DispatchCallback::create(0, $item['outcome'], $item['message'], [], $now)->getMessageText(), $now);
            $this->trackings->saveEntity($tracking);

            if (!$changed) {
                ++$summary['unchanged'];

                continue;
            }

            $this->callbacks->saveEntity(DispatchCallback::create((int) $tracking->getId(), $item['outcome'], $item['message'], $item['body'], $now)->withLog($tracking->getCampaignLogId(), $tracking->getRefType(), $tracking->getRefValue()));
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
                'message'    => $callback->getMessageText(),
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
     * Ties the history entries of these rows that have no log yet to the log.
     *
     * @param list<DispatchTracking> $rows
     */
    private function linkHistory(array $rows, int $campaignLogId): void
    {
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row->getId()] = $row;
        }

        foreach ($this->callbacks->findByTrackingIds(array_keys($byId)) as $entry) {
            if (null !== $entry->getCampaignLogId() || !isset($byId[(int) $entry->getTrackingId()])) {
                continue;
            }

            $row = $byId[(int) $entry->getTrackingId()];
            $entry->withLog($campaignLogId, $row->getRefType(), $row->getRefValue());
            $this->callbacks->saveEntity($entry);
        }
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
