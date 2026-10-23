<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

use Mautic\CampaignBundle\Entity\LeadEventLog;

/**
 * What the Email, SMS and HSM campaign actions keep once n8n answered, accepted
 * or not: the full answer in the log's metadata, the attempt in the log's
 * History, and, for an accepted dispatch, the registration the status poll
 * works from (see StatusPoller). The three channels differ only in which ids
 * n8n returns, so each caller names them.
 */
final class DispatchOutcomeRecorder
{
    /**
     * @param list<array{body: string, meta: string, ref: string}> $ids   one per id n8n returns: `body` is its key in
     *                                                                   n8n's answer (also looked up under the answer's
     *                                                                   own `body`), `meta` the key kept in the log's
     *                                                                   metadata, `ref` its DispatchTracking::REF_*
     * @param array<string, mixed>                                 $payload  what was sent to n8n
     * @param array<string, mixed>                                 $extra    more entries for the log's `n8ndispatch` metadata
     * @param bool                                                 $accepted whether the History counts the attempt as accepted
     */
    public static function record(
        ?StatusTracker $statusTracker,
        LeadEventLog $log,
        string $channel,
        array $ids,
        array $payload,
        string $rawBody,
        int $statusCode,
        bool $accepted,
        array $extra = [],
    ): void {
        $body       = json_decode($rawBody, true);
        $nestedBody = is_array($body['body'] ?? null) ? $body['body'] : [];

        $refs     = [];
        $metadata = [];

        foreach ($ids as $id) {
            $value = is_array($body) ? ($body[$id['body']] ?? $nestedBody[$id['body']] ?? null) : null;

            $refs[$id['ref']] = $value;

            if (!empty($value)) {
                $metadata[$id['meta']] = $value;
            }
        }

        // 'response' is the raw decoded body (falling back to the raw string
        // when it isn't valid JSON) — the Timeline card just dumps it as-is,
        // so if Mirror's response shape grows new fields later, they show up
        // there automatically, no template change needed.
        $n8ndispatch = $payload + [
            'response'       => is_array($body) ? $body : $rawBody,
            'httpStatusCode' => $statusCode,
        ] + $extra;

        // The log was persisted before the step ran, so it has its id; it ties
        // this attempt, and what n8n reports about it later, to the contact's
        // History entry (a resend reuses the same log).
        $campaignLogId = $log->getId();

        // Every attempt goes in the log's history, accepted or not, with only n8n's answer (kept in case it has to be reprocessed).
        $statusTracker?->recordDispatch($campaignLogId, $accepted, $refs, is_array($body) ? $body : ('' !== trim($rawBody) ? trim($rawBody) : null));

        // Only a dispatch n8n accepted is worth asking about later (see StatusPoller).
        if ($statusCode < 300 && [] !== $metadata) {
            $statusTracker?->register($channel, $refs, null, $campaignLogId);
        }

        $log->appendToMetadata(['n8ndispatch' => $n8ndispatch] + $metadata);
    }
}
