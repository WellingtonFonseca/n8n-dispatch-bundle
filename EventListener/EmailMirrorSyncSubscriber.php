<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\EventListener;

use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Event\EmailEvent;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\N8nDispatchBundle\Integration\N8nDispatchIntegration;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Whenever an Email template is saved in Mautic (UI or API — both go
 * through EmailModel::saveEntity(), which dispatches EMAIL_POST_SAVE),
 * sends {mautic_template_id, html, hash, from_name, from_address} to the
 * endpoint configured on the "N8n Dispatch" integration (Settings >
 * Plugins). from_name/from_address are included because a single
 * institution can have more than one sender identity across its
 * templates — the endpoint needs to know which one this template uses,
 * not just its content. The endpoint (n8n today, but this side only
 * knows it as "the configured URL") owns deciding whether the content
 * actually changed and whether to create/update the template in Mirror
 * — this listener does not track any state of its own, it just reports
 * the email's current fields on every save.
 *
 * from_name/from_address come straight off the Email entity and can be
 * null — Mautic falls back to the system-wide default sender at actual
 * send time if a template doesn't set its own, but that fallback isn't
 * resolved here, so a null in this payload means "uses the system
 * default", not "no sender".
 */
class EmailMirrorSyncSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private IntegrationHelper $integrationHelper,
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            EmailEvents::EMAIL_POST_SAVE => 'onEmailPostSave',
        ];
    }

    public function onEmailPostSave(EmailEvent $event): void
    {
        $integration = $this->integrationHelper->getIntegrationObject(N8nDispatchIntegration::NAME);

        if (!$integration || !$integration->getIntegrationSettings()->isPublished()) {
            return;
        }

        $keys       = $integration->getKeys();
        $webhookUrl = trim((string) ($keys['webhook_url'] ?? ''));

        if ('' === $webhookUrl) {
            $this->logger->warning('N8nDispatch: webhook_url is not configured, skipping Mirror sync for email post-save.');

            return;
        }

        $email       = $event->getEmail();
        $html        = (string) $email->getCustomHtml();
        $hash        = hash('sha256', $html);
        $fromName    = $email->getFromName();
        $fromAddress = $email->getFromAddress();

        $headers = ['Content-Type' => 'application/json'];
        $token   = trim((string) ($keys['webhook_token'] ?? ''));
        if ('' !== $token) {
            // Plain custom header, no "Bearer " prefix — matches n8n's Header
            // Auth credential (a literal Name + Value pair on the Webhook
            // node), which has no built-in Bearer/OAuth2 concept of its own.
            $headers['X-N8n-Dispatch-Token'] = $token;
        }

        try {
            $response = $this->httpClient->request('POST', $webhookUrl, [
                'headers' => $headers,
                'json'    => [
                    'mautic_template_id' => $email->getId(),
                    'html'               => $html,
                    'hash'               => $hash,
                    'from_name'          => $fromName,
                    'from_address'       => $fromAddress,
                ],
            ]);

            // Symfony's HttpClient sends the request lazily — transport-level
            // failures (connection refused, DNS, timeout) only surface once the
            // response is actually read, so this call must happen inside the
            // try block for the catch below to ever see them.
            $statusCode = $response->getStatusCode();

            if ($statusCode >= 300) {
                $this->logger->error('N8nDispatch: webhook returned HTTP '.$statusCode.' for email '.$email->getId().'.');
            }
        } catch (\Throwable $e) {
            // The Email is already saved in Mautic by this point (POST_SAVE) —
            // a sync failure here must not surface as a save error to the user.
            $this->logger->error('N8nDispatch: failed to sync email '.$email->getId().' to the configured webhook: '.$e->getMessage());
        }
    }
}
