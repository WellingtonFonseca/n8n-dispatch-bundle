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
 * Any failure of the call itself (webhook down, non-2xx, a body that is not
 * the expected JSON) leaves every id as it was: nothing is applied, so the
 * next run asks again.
 */
class StatusPoller
{
    public function __construct(
        private IntegrationHelper $integrationHelper,
        private HttpClientInterface $httpClient,
        private StatusTracker $tracker,
        private StatusResponseParser $parser,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{requested: int, changed: int, unchanged: int, unknown: int, invalid: int, error: ?string}
     */
    public function poll(string $channel, int $limit, int $maxAgeDays, string $outcome, ?string $webhookUrlOverride, \DateTimeImmutable $now): array
    {
        $summary = ['requested' => 0, 'changed' => 0, 'unchanged' => 0, 'unknown' => 0, 'invalid' => 0, 'error' => null];

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

        $rows = $this->tracker->pending($channel, $outcome, $limit, $maxAgeDays, $now);

        if ([] === $rows) {
            return $summary;
        }

        $items                = $this->buildItems($rows);
        $summary['requested'] = count($items);

        $headers = [
            'Content-Type'          => 'application/json',
            'X-N8n-Dispatch-Action' => $channel.'.status',
        ];
        $token = trim((string) ($keys['webhook_token'] ?? ''));
        if ('' !== $token) {
            $headers['X-N8n-Dispatch-Token'] = $token;
        }

        try {
            $response = $this->httpClient->request('POST', $webhookUrl, [
                'headers' => $headers,
                'json'    => ['items' => $items],
            ]);

            // Symfony's HttpClient sends lazily — see the dispatch
            // listeners for the same getStatusCode()-inside-try requirement.
            $statusCode = $response->getStatusCode();
            $rawBody    = $response->getContent(false);
        } catch (\Throwable $e) {
            $this->logger->error('N8nDispatch: '.$channel.'.status call failed: '.$e->getMessage());
            $summary['error'] = $e->getMessage();

            return $summary;
        }

        if ($statusCode >= 300) {
            $summary['error'] = 'webhook returned HTTP '.$statusCode.'.';

            return $summary;
        }

        $parsed = $this->parser->parse($channel, $rawBody);

        if (null === $parsed) {
            $summary['error'] = 'the response is not a JSON object with an "items" list.';

            return $summary;
        }

        $applied            = $this->tracker->apply($parsed['items'], $now);
        $summary['invalid'] = $parsed['invalid'];

        return array_merge($summary, $applied);
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
