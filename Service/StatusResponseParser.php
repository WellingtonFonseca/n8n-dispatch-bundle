<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

use MauticPlugin\N8nDispatchBundle\Entity\DispatchTracking;

/**
 * Reads n8n's answer to a '<channel>.status' call: {"items": [...]}, each
 * item carrying one id of the channel plus an 'outcome' (success/error/
 * pending) and an optional 'message'. The list is also accepted one level
 * under 'body', the same nesting n8n's "Respond to Webhook" node already
 * produces for the dispatch response.
 *
 * Only validates the shape; deciding what a valid item means for a tracked
 * id is StatusTracker's job.
 */
class StatusResponseParser
{
    /**
     * @return array{items: list<array{refType: string, refValue: string, outcome: string, message: mixed, body: array<string, mixed>}>, invalid: int}|null
     *                                                                                                                                                       null when the body is not JSON or has no items list at all
     */
    public function parse(string $channel, string $rawBody): ?array
    {
        $decoded = json_decode($rawBody, true);

        if (!is_array($decoded)) {
            return null;
        }

        $list = $decoded['items'] ?? ($decoded['body']['items'] ?? null);

        if (!is_array($list) || !array_is_list($list)) {
            return null;
        }

        $refTypes = DispatchTracking::REFS_BY_CHANNEL[$channel] ?? [];
        $items    = [];
        $invalid  = 0;

        foreach ($list as $item) {
            $parsed = is_array($item) ? $this->parseItem($refTypes, $item) : null;

            if (null === $parsed) {
                ++$invalid;

                continue;
            }

            $items[] = $parsed;
        }

        return ['items' => $items, 'invalid' => $invalid];
    }

    /**
     * @param list<string>         $refTypes
     * @param array<string, mixed> $item
     *
     * @return array{refType: string, refValue: string, outcome: string, message: mixed, body: array<string, mixed>}|null
     */
    private function parseItem(array $refTypes, array $item): ?array
    {
        $outcome = $item['outcome'] ?? null;

        if (!is_string($outcome) || !in_array($outcome, DispatchTracking::OUTCOMES, true)) {
            return null;
        }

        foreach ($refTypes as $refType) {
            $value = $item[$refType] ?? null;

            if ((is_int($value) || is_string($value)) && '' !== (string) $value) {
                $message = $item['message'] ?? null;

                return [
                    'refType'  => $refType,
                    'refValue' => (string) $value,
                    'outcome'  => $outcome,
                    'message'  => (is_string($message) && '' !== $message) || (is_array($message) && [] !== $message) || is_int($message) || is_float($message) ? $message : null,
                    'body'     => $item,
                ];
            }
        }

        return null;
    }
}
