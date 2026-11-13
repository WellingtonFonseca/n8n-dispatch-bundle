<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\N8nDispatchBundle\Entity\MirrorSyncState;
use MauticPlugin\N8nDispatchBundle\Entity\MirrorSyncStateRepository;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The only sender of email.save: posts one Email to the webhook and records the result, which the resync job reads.
 * A failure is logged and recorded, never thrown: the save that calls it has already happened.
 */
class MirrorSync
{
    public const ACTION = 'email.save';

    /** Seconds without a byte from n8n, and seconds a whole call may last: a save or a job run never waits longer. */
    public const TIMEOUT      = 30;
    public const MAX_DURATION = 60;

    public function __construct(
        private IntegrationHelper $integrationHelper,
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private MirrorSyncPayload $payload,
        private MirrorSyncStateRepository $states,
    ) {
    }

    /** False when the integration is off or has no webhook URL: nothing is sent and nothing is recorded. */
    public function isConfigured(): bool
    {
        $webhook = WebhookSettings::forAction($this->integrationHelper, self::ACTION);

        if (DispatchFailureReasons::WEBHOOK_MISSING === $webhook) {
            $this->logger->warning('N8nDispatch: Mirror sync skipped, webhook_url is not configured.');
        }

        return is_array($webhook);
    }

    /**
     * Sends the Email (or skips it when its HTML is empty) and returns the recorded MirrorSyncState status, or null
     * when the webhook is not configured.
     *
     * @param array<string, ?string> $fields see MirrorSyncPayload::fieldsOf()
     */
    public function sync(int $emailId, array $fields, ?string $modifiedByEmail, string $source, \DateTimeImmutable $now): ?string
    {
        $webhook = WebhookSettings::forAction($this->integrationHelper, self::ACTION);

        if (!is_array($webhook)) {
            return null;
        }

        $contentHash = $this->payload->contentHash($fields);

        if (!$this->payload->hasContent($fields)) {
            $this->record($emailId, MirrorSyncState::STATUS_SKIPPED, $contentHash, null, null, $source, $now);

            return MirrorSyncState::STATUS_SKIPPED;
        }

        [$httpStatus, $error] = $this->post($webhook, $emailId, $this->payload->build($emailId, $fields, $modifiedByEmail));
        $status               = null === $error ? MirrorSyncState::STATUS_OK : MirrorSyncState::STATUS_FAILED;
        $this->record($emailId, $status, $contentHash, $httpStatus, $error, $source, $now);

        return $status;
    }

    /**
     * @param array{url: string, headers: array<string, string>} $webhook
     * @param array<string, mixed>                               $json
     *
     * @return array{0: ?int, 1: ?string} the HTTP status (null on a transport failure) and the error (null on success)
     */
    private function post(array $webhook, int $emailId, array $json): array
    {
        try {
            $response = $this->httpClient->request('POST', $webhook['url'], [
                'headers'      => $webhook['headers'],
                'json'         => $json,
                'timeout'      => self::TIMEOUT,
                'max_duration' => self::MAX_DURATION,
            ]);
            // HttpClient is lazy: reading the status waits for n8n, so transport errors surface here and the calls
            // of the resync job never overlap.
            $statusCode = $response->getStatusCode();

            if ($statusCode >= 300) {
                $this->logger->error('N8nDispatch: webhook returned HTTP '.$statusCode.' for email '.$emailId.'.');

                return [$statusCode, 'HTTP '.$statusCode];
            }

            $error = DispatchFailureReasons::errorInBody($response->getContent(false));

            if (null !== $error) {
                $this->logger->error('N8nDispatch: webhook answered an error for email '.$emailId.': '.$error);
            }

            return [$statusCode, $error];
        } catch (\Throwable $e) {
            $this->logger->error('N8nDispatch: failed to sync email '.$emailId.' to the configured webhook: '.$e->getMessage());

            return [null, $e->getMessage()];
        }
    }

    private function record(int $emailId, string $status, string $contentHash, ?int $httpStatus, ?string $error, string $source, \DateTimeImmutable $now): void
    {
        try {
            $this->states->record($emailId, $status, $contentHash, $httpStatus, $error, $source, $now);
        } catch (\Throwable $e) {
            // The table is missing until mautic:plugins:reload runs after a deploy: the save must still work.
            $this->logger->error('N8nDispatch: could not record the Mirror sync of email '.$emailId.': '.$e->getMessage());
        }
    }
}
