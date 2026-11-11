<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service\Alert;

use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\N8nDispatchBundle\Entity\AlertLogEntry;
use MauticPlugin\N8nDispatchBundle\Entity\AlertLogEntryRepository;
use MauticPlugin\N8nDispatchBundle\Service\WebhookSettings;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Posts an alert to the same webhook as the dispatches, with the header `X-N8n-Dispatch-Action: alert.<type>`, so the
 * n8n workflow routes it (Teams, email, WhatsApp). An alert never breaks the code that raises it: a failure is logged.
 * It cannot reach n8n when the integration is off or the webhook is missing, or when n8n itself is down.
 */
class AlertSender
{
    private const TIMEOUT_SECONDS = 10;

    public function __construct(
        private IntegrationHelper $integrationHelper,
        private AlertSettings $settings,
        private AlertThrottle $throttle,
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private AlertLogEntryRepository $alertLog,
    ) {
    }

    /**
     * @param array<string, mixed> $data       what the alert says (ids, names, numbers, link)
     * @param string|null          $onceKey    with a cooldown: send only when no alert of this key went out in `$cooldownSeconds`
     */
    public function send(string $type, array $data, ?string $onceKey = null, int $cooldownSeconds = 0): bool
    {
        try {
            return $this->sendOnce($type, $data, $onceKey, $cooldownSeconds);
        } catch (\Throwable $e) {
            // the caller is core's failure handling, a Resend or a cron command: an alert must not break any of them
            $this->logger->error('N8nDispatch: alert alert.'.$type.' not sent: '.$e->getMessage());

            return false;
        }
    }

    /**
     * A key still in its cooldown ends the call without a log row: the alert of that key was logged when it went out
     * (or was held back), and a failing step of a big campaign gets here once per contact.
     *
     * @param array<string, mixed> $data
     */
    private function sendOnce(string $type, array $data, ?string $onceKey, int $cooldownSeconds): bool
    {
        $settings = $this->settings->current();

        if (null !== $onceKey && !$this->throttle->claim($onceKey, $cooldownSeconds)) {
            return false;
        }

        // the key stays taken while the alerts are off: one log row per cooldown, not one per contact
        if (!$settings['enabled']) {
            $this->record($type, $data, AlertLogEntry::OUTCOME_NOT_SENT, AlertLogEntry::REASON_ALERTS_OFF, null);

            return false;
        }

        $failure = $this->post($type, $data, $settings['instanceName']);

        if (null === $failure) {
            $this->record($type, $data, AlertLogEntry::OUTCOME_SENT, null, null);

            return true;
        }

        if (null !== $onceKey) {
            $this->throttle->release($onceKey);
        }

        $this->record($type, $data, AlertLogEntry::OUTCOME_NOT_SENT, $failure['reason'], $failure['detail']);

        return false;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{reason: string, detail: string}|null null when the webhook accepted the alert
     */
    private function post(string $type, array $data, string $instanceName): ?array
    {
        $webhook = WebhookSettings::forAction($this->integrationHelper, 'alert.'.$type);

        if (is_string($webhook)) {
            $this->logger->warning('N8nDispatch: alert alert.'.$type.' not sent: '.$webhook);

            return ['reason' => AlertLogEntry::REASON_WEBHOOK_MISSING, 'detail' => $webhook];
        }

        try {
            $status = $this->httpClient->request('POST', $webhook['url'], [
                'headers' => $webhook['headers'],
                'timeout' => self::TIMEOUT_SECONDS,
                'json'    => ['alert' => $type, 'instance' => $instanceName, 'occurred_at' => (new \DateTimeImmutable())->format(\DATE_ATOM), 'data' => $data],
            ])->getStatusCode();
        } catch (\Throwable $e) {
            $this->logger->error('N8nDispatch: alert alert.'.$type.' failed: '.$e->getMessage());

            return ['reason' => AlertLogEntry::REASON_ERROR, 'detail' => $e->getMessage()];
        }

        if ($status >= 300) {
            $this->logger->error('N8nDispatch: alert alert.'.$type.' refused by the webhook: HTTP '.$status);

            return ['reason' => AlertLogEntry::REASON_REFUSED, 'detail' => 'HTTP '.$status];
        }

        return null;
    }

    /**
     * A failure to write the log is logged and goes no further: the log is a view, it must not break the alert or its caller.
     *
     * @param array<string, mixed> $data
     */
    private function record(string $type, array $data, string $outcome, ?string $reason, ?string $detail): void
    {
        // most alerts carry `campaign` {id, name, url}; the bounce spike carries the campaign's own `name`
        $campaign = $data['campaign'] ?? $data;
        $subject  = is_array($campaign) && is_string($campaign['name'] ?? null) ? $campaign['name'] : null;

        try {
            $this->alertLog->record(new \DateTimeImmutable(), $type, $outcome, $reason, $detail, $subject);
        } catch (\Throwable $e) {
            $this->logger->error('N8nDispatch: alert alert.'.$type.' not written to the alert log: '.$e->getMessage());
        }
    }
}
