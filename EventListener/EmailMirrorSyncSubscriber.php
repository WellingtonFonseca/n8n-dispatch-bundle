<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\EventListener;

use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Event\EmailEvent;
use Mautic\EmailBundle\EventListener\EmailSubscriber as CoreEmailSubscriber;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\N8nDispatchBundle\Integration\N8nDispatchIntegration;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Whenever an Email template is saved in Mautic (UI or API — both go
 * through EmailModel::saveEntity(), which dispatches EMAIL_POST_SAVE),
 * sends {mautic_template_id, subject, html, hash, from_name, from_address,
 * modified_by_email} to the endpoint configured on the "N8n Dispatch"
 * integration (Settings > Plugins). from_name/from_address are included
 * because a single institution can have more than one sender identity
 * across its templates — the endpoint needs to know which one this
 * template uses, not just its content. The endpoint (n8n today, but this
 * side only knows it as "the configured URL") owns deciding whether the
 * content actually changed and whether to create/update the template in
 * Mirror — this listener does not track any state of its own, it just
 * reports the email's current fields on every save.
 *
 * from_name/from_address come straight off the Email entity and can be
 * null — Mautic falls back to the system-wide default sender at actual
 * send time if a template doesn't set its own, but that fallback isn't
 * resolved here, so a null in this payload means "uses the system
 * default", not "no sender".
 *
 * modified_by_email is the currently authenticated Mautic user's email
 * (via UserHelper, already hydrated from the request's security token —
 * no extra query). It's null for saves with no logged-in user (CLI,
 * system jobs), which is a valid, expected value here.
 *
 * The preheader is NOT stored as part of customHtml — Mautic keeps it in
 * its own preheaderText column and only splices it into the HTML at
 * actual send/preview time (EmailBundle\EventListener\EmailSubscriber::
 * onEmailSendAddPreheaderText, which runs on a send-time event, not on
 * save). Since Mirror doesn't do that injection itself, this listener
 * reproduces the same injection here — using the core subscriber's own
 * public constants/patterns, not a reimplementation — so the html sent
 * out matches what Mautic would actually send.
 */
class EmailMirrorSyncSubscriber implements EventSubscriberInterface
{
    /**
     * Sent as the X-N8n-Dispatch-Action header on every call, since this
     * sync call and the real dispatch call (CampaignTriggerSubscriber,
     * X-N8n-Dispatch-Action: email.send) share the same configured
     * webhook_url. Without this, n8n would have no reliable way to tell
     * them apart other than guessing from the request body's shape.
     *
     * Named 'email.save' (channel.verb), not the channel-agnostic
     * 'template.sync' it started as, so that SMS/HSM template saves can
     * later get their own 'sms.save'/'hsm.save' siblings without
     * colliding on one shared, ambiguous name — same pattern already used
     * on the dispatch side ('email.send', with 'sms.send'/'hsm.send' as
     * the equivalent future siblings there).
     */
    private const ACTION = 'email.save';

    public function __construct(
        private IntegrationHelper $integrationHelper,
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private UserHelper $userHelper,
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

        $email           = $event->getEmail();
        $html            = $this->injectPreheader((string) $email->getCustomHtml(), $email->getPreheaderText());
        $hash            = hash('sha256', $html);
        $fromName        = $email->getFromName();
        $fromAddress     = $email->getFromAddress();
        $modifiedByEmail = $this->userHelper->getUser(true)?->getEmail();

        $headers = [
            'Content-Type'          => 'application/json',
            'X-N8n-Dispatch-Action' => self::ACTION,
        ];
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
                    'subject'            => $email->getSubject(),
                    'html'               => $html,
                    'hash'               => $hash,
                    'from_name'          => $fromName,
                    'from_address'       => $fromAddress,
                    'modified_by_email'  => $modifiedByEmail,
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

    /**
     * Mirrors EmailBundle\EventListener\EmailSubscriber::onEmailSendAddPreheaderText
     * exactly (same constants, same replace-or-insert logic) so the HTML sent
     * out here matches what Mautic itself would produce at send time.
     */
    private function injectPreheader(string $html, ?string $preheaderText): string
    {
        if (!$preheaderText) {
            return $html;
        }

        $preheaderElement = CoreEmailSubscriber::PREHEADER_HTML_ELEMENT_BEFORE.$preheaderText.CoreEmailSubscriber::PREHEADER_HTML_ELEMENT_AFTER;

        if (preg_match(CoreEmailSubscriber::PREHEADER_HTML_SEARCH_PATTERN, $html)) {
            return preg_replace(CoreEmailSubscriber::PREHEADER_HTML_REPLACE_PATTERN, $preheaderElement, $html);
        }

        if (preg_match('/(<body[^>]*>)/i', $html, $bodyMatch)) {
            return str_ireplace($bodyMatch[0], $bodyMatch[0]."\n".$preheaderElement, $html);
        }

        return $html;
    }
}
