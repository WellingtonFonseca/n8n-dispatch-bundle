<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

use MauticPlugin\N8nDispatchBundle\Entity\DispatchTracking;

/**
 * Reads the ids of a dispatch back out of a campaign log's metadata, for the
 * backfill (StatusBackfill) of dispatches made before the status poll
 * existed. Only a real dispatch that n8n accepted has anything to read:
 * 'production' mode, no HTTP error, and an id.
 *
 * The HSM's Meta uuid was never kept apart before the status poll, but the
 * whole response n8n gave is stored in metadata['n8ndispatch']['response'],
 * flat or one level under 'body', so it is read from there when needed.
 */
class DispatchLogReader
{
    private const CHANNEL_BY_EVENT_TYPE = [
        'n8ndispatch.email.send' => DispatchTracking::CHANNEL_EMAIL,
        'n8ndispatch.sms.send'   => DispatchTracking::CHANNEL_SMS,
        'n8ndispatch.hsm.send'   => DispatchTracking::CHANNEL_HSM,
    ];

    /**
     * @return list<string> the campaign event types of the three actions
     */
    public static function eventTypes(): array
    {
        return array_keys(self::CHANNEL_BY_EVENT_TYPE);
    }

    public static function channelOf(string $eventType): ?string
    {
        return self::CHANNEL_BY_EVENT_TYPE[$eventType] ?? null;
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @return array<string, int|string> refType => id; empty when this is not a dispatch to track
     */
    public function refs(string $channel, array $metadata): array
    {
        $n8n = $metadata['n8ndispatch'] ?? null;

        if (!is_array($n8n) || 'production' !== ($n8n['status'] ?? null)) {
            return [];
        }

        if (isset($n8n['httpStatusCode']) && (int) $n8n['httpStatusCode'] >= 300) {
            return [];
        }

        $response = is_array($n8n['response'] ?? null) ? $n8n['response'] : [];
        $nested   = is_array($response['body'] ?? null) ? $response['body'] : [];

        $refs = match ($channel) {
            DispatchTracking::CHANNEL_EMAIL => [
                DispatchTracking::REF_EMAIL => $metadata['logSendEmailId'] ?? $response['logSendEmailId'] ?? $nested['logSendEmailId'] ?? null,
            ],
            DispatchTracking::CHANNEL_SMS => [
                DispatchTracking::REF_SMS => $metadata['logSendSmsId'] ?? $response['logSendSmsId'] ?? $nested['logSendSmsId'] ?? null,
            ],
            DispatchTracking::CHANNEL_HSM => [
                DispatchTracking::REF_HSM_ID   => $metadata['logSendHsmId'] ?? $response['logSendHsmId'] ?? $nested['logSendHsmId'] ?? null,
                DispatchTracking::REF_HSM_UUID => $metadata['logSendHsmUuid'] ?? $response['uuid'] ?? $nested['uuid'] ?? null,
            ],
            default => [],
        };

        return array_filter(
            $refs,
            static fn ($value): bool => (is_int($value) || is_string($value)) && '' !== (string) $value
        );
    }

    /**
     * What a campaign log says about its dispatch, for the backfill: the mode
     * the step was in ('production', 'test' or 'paused'), whether n8n accepted
     * the call, n8n's answer (decoded JSON, or the text; null when empty) and
     * the ids to track. Null when the log is not a dispatch (a cancelled row, or
     * one with no 'n8ndispatch' block).
     *
     * @param array<string, mixed> $metadata
     *
     * @return array{mode: string, accepted: bool, response: mixed, refs: array<string, int|string>}|null
     */
    public function dispatchOf(string $channel, array $metadata): ?array
    {
        $n8n = $metadata['n8ndispatch'] ?? null;
        $mode = is_array($n8n) ? ($n8n['status'] ?? null) : null;

        if (!in_array($mode, ['production', 'test', 'paused'], true)) {
            return null;
        }

        if ('production' !== $mode) {
            return ['mode' => $mode, 'accepted' => true, 'response' => null, 'refs' => []];
        }

        $response = $n8n['response'] ?? null;

        return [
            'mode'     => 'production',
            'accepted' => !(isset($n8n['httpStatusCode']) && (int) $n8n['httpStatusCode'] >= 300),
            'response' => is_array($response) || (is_string($response) && '' !== trim($response)) ? $response : null,
            'refs'     => $this->refs($channel, $metadata),
        ];
    }
}
