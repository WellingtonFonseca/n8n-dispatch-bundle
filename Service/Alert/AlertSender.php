<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service\Alert;

use Mautic\PluginBundle\Helper\IntegrationHelper;
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
     * @param array<string, mixed> $data
     */
    private function sendOnce(string $type, array $data, ?string $onceKey, int $cooldownSeconds): bool
    {
        if (!$this->settings->current()['enabled']) {
            return false;
        }

        if (null !== $onceKey && !$this->throttle->claim($onceKey, $cooldownSeconds)) {
            return false;
        }

        if ($this->post($type, $data)) {
            return true;
        }

        if (null !== $onceKey) {
            $this->throttle->release($onceKey);
        }

        return false;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function post(string $type, array $data): bool
    {
        $webhook = WebhookSettings::forAction($this->integrationHelper, 'alert.'.$type);

        if (is_string($webhook)) {
            $this->logger->warning('N8nDispatch: alert alert.'.$type.' not sent: '.$webhook);

            return false;
        }

        try {
            $status = $this->httpClient->request('POST', $webhook['url'], [
                'headers' => $webhook['headers'],
                'timeout' => self::TIMEOUT_SECONDS,
                'json'    => ['alert' => $type, 'occurred_at' => (new \DateTimeImmutable())->format(\DATE_ATOM), 'data' => $data],
            ])->getStatusCode();
        } catch (\Throwable $e) {
            $this->logger->error('N8nDispatch: alert alert.'.$type.' failed: '.$e->getMessage());

            return false;
        }

        if ($status >= 300) {
            $this->logger->error('N8nDispatch: alert alert.'.$type.' refused by the webhook: HTTP '.$status);

            return false;
        }

        return true;
    }
}
