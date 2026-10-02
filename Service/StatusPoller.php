<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\N8nDispatchBundle\Entity\DispatchTracking;
use MauticPlugin\N8nDispatchBundle\Integration\N8nDispatchIntegration;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Asks n8n, through the same webhook every other call uses, how the
 * dispatches of one channel ended up. The call carries the header
 * X-N8n-Dispatch-Action: <channel>.status and a batch of the ids still
 * waiting for an answer; the answer is parsed by StatusResponseParser and
 * applied by StatusTracker.
 *
 * Any failure of a call (webhook down, non-2xx, a body that is not the
 * expected JSON) applies nothing from that call, so the next run asks again;
 * the calls that already succeeded in the run stay applied.
 */
class StatusPoller
{
    /** Ceiling of calls in one run per channel: 100,000 ids at 100 a call. Only there so a bug can never loop forever. */
    public const MAX_CALLS = 1000;

    /** Seconds without a byte from n8n before a call is given up; and the most a whole call may last. */
    public const REQUEST_TIMEOUT      = 60;
    public const REQUEST_MAX_DURATION = 120;

    public function __construct(
        private IntegrationHelper $integrationHelper,
        private HttpClientInterface $httpClient,
        private StatusTracker $tracker,
        private StatusResponseParser $parser,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Asks about every pending id of the channel, $batchSize at a time, one
     * call after the other, until the queue is empty. Each batch starts after
     * the last id of the one before, so nothing is asked twice in a run.
     * The run ends early when a call fails (what was already answered stays
     * applied), when a call makes no progress at all (n8n answered nothing
     * usable) or after $maxCalls calls — a safety net, not a throttle.
     *
     * @return array{requested: int, changed: int, unchanged: int, unknown: int, invalid: int, calls: int, error: ?string}
     */
    public function poll(string $channel, int $batchSize, int $maxAgeDays, string $outcome, ?string $webhookUrlOverride, \DateTimeImmutable $now, int $maxCalls = self::MAX_CALLS): array
    {
        $summary = ['requested' => 0, 'changed' => 0, 'unchanged' => 0, 'unknown' => 0, 'invalid' => 0, 'calls' => 0, 'error' => null];

        $integration = $this->integrationHelper->getIntegrationObject(N8nDispatchIntegration::NAME);
        $keys        = $integration ? $integration->getKeys() : [];

        $webhookUrl = trim((string) $webhookUrlOverride);

        if ('' === $webhookUrl) {
            if (!$integration || !$integration->getIntegrationSettings()->isPublished()) {
                $summary['error'] = 'the N8n Dispatch integration is not configured/enabled.';

                return $summary;
            }

            $webhookUrl = trim((string) ($keys['webhook_url'] ?? ''));

            if ('' === $webhookUrl) {
                $summary['error'] = 'webhook_url is not configured.';

                return $summary;
            }
        }

        $headers = [
            'Content-Type'          => 'application/json',
            'X-N8n-Dispatch-Action' => $channel.'.status',
        ];
        $token = trim((string) ($keys['webhook_token'] ?? ''));
        if ('' !== $token) {
            $headers['X-N8n-Dispatch-Token'] = $token;
        }

        $afterId = 0;

        while ($summary['calls'] < $maxCalls) {
            $rows = $this->tracker->pending($channel, $outcome, $batchSize, $maxAgeDays, $now, $afterId);

            if ([] === $rows) {
                break;
            }

            $afterId = max(array_map(static fn (DispatchTracking $row): int => (int) $row->getId(), $rows));
            $items   = $this->buildItems($rows);

            try {
                $response = $this->httpClient->request('POST', $webhookUrl, [
                    'headers'      => $headers,
                    'json'         => ['items' => $items],
                    // An n8n that hangs must not hold the run (and, through it,
                    // the next scheduled ones) forever: the call fails like any
                    // other, the run stops, and the next run asks again.
                    'timeout'      => self::REQUEST_TIMEOUT,
                    'max_duration' => self::REQUEST_MAX_DURATION,
                ]);

                // Symfony's HttpClient sends lazily — see the dispatch
                // listeners for the same getStatusCode()-inside-try requirement.
                $statusCode = $response->getStatusCode();
                $rawBody    = $response->getContent(false);
            } catch (\Throwable $e) {
                $this->logger->error('N8nDispatch: '.$channel.'.status call failed: '.$e->getMessage());
                $summary['error'] = $e->getMessage();

                break;
            }

            if ($statusCode >= 300) {
                $summary['error'] = 'webhook returned HTTP '.$statusCode.'.';

                break;
            }

            $parsed = $this->parser->parse($channel, $rawBody);

            if (null === $parsed) {
                $summary['error'] = 'the response is not a JSON object with an "items" list.';

                break;
            }

            $applied = $this->tracker->apply($parsed['items'], $now);

            ++$summary['calls'];
            $summary['requested'] += count($items);
            $summary['invalid']   += $parsed['invalid'];

            foreach (['changed', 'unchanged', 'unknown'] as $key) {
                $summary[$key] += $applied[$key];
            }

            if (count($rows) < $batchSize || 0 === array_sum($applied)) {
                break;
            }
        }

        return $summary;
    }

    /**
     * One item per dispatch: the rows sharing a group key (the HSM Mirror
     * id and Meta uuid) travel together. Mirror-style numeric ids go out as
     * numbers, the uuid as a string.
     *
     * @param list<DispatchTracking> $rows
     *
     * @return list<array<string, int|string>>
     */
    private function buildItems(array $rows): array
    {
        $byGroup = [];

        foreach ($rows as $row) {
            $value = $row->getRefValue();

            $byGroup[$row->getGroupKey()][$row->getRefType()] = (DispatchTracking::REF_HSM_UUID !== $row->getRefType() && ctype_digit($value))
                ? (int) $value
                : $value;
        }

        return array_values($byGroup);
    }
}
