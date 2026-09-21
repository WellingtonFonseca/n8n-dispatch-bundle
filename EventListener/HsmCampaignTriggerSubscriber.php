<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\EventListener;

use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\LeadEventLog;
use Mautic\CampaignBundle\Event\PendingEvent;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\N8nDispatchBundle\Integration\N8nDispatchIntegration;
use MauticPlugin\N8nDispatchBundle\N8nDispatchEvents;
use MauticPlugin\N8nDispatchBundle\Resolver\VariableResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Handles the "Send via n8n (HSM)" Campaign Action — same
 * status/dispatch/outcome shape as CampaignTriggerSubscriber (Email) and
 * SmsCampaignTriggerSubscriber (SMS), see either one's own docblock for
 * the full 'test'/'production'/'paused' rationale, reused here as-is.
 *
 * Differences from both of those:
 * - No text/message at all — HSM has no Mautic-side template content,
 *   only a 'router' (which WhatsApp line sends it) and 'hsmId' (which
 *   template), both plain values the user typed into the form
 *   (Form/Type/HsmDispatchActionType.php). 'variables' is resolved and
 *   sent as-is, for n8n/WhatsApp to fill into the template itself — there
 *   is no local substitution step the way SMS's 'message' needed, since
 *   there's no local text to substitute into.
 * - No native Mautic history entity: unlike Email (Stat/email_stats) and
 *   SMS (Stat/sms_message_stats), Mautic core has no HSM/WhatsApp bundle
 *   at all to hook a Stat into (see the plugin's original architecture
 *   decision — this is exactly why HSM needs the Campaign Action pattern
 *   in the first place). The dispatch outcome still lands on the
 *   contact's Timeline through this plugin's own generic
 *   'n8ndispatch'-metadata card (same timelineTemplate as Email/SMS), just
 *   without a second, natively-categorized entry alongside it.
 */
class HsmCampaignTriggerSubscriber implements EventSubscriberInterface
{
    private const CONTEXT = 'n8ndispatch.hsm.send';
    private const ACTION  = 'hsm.send';

    public function __construct(
        private IntegrationHelper $integrationHelper,
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private VariableResolver $variableResolver,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            N8nDispatchEvents::ON_CAMPAIGN_TRIGGER_HSM_SEND => 'onHsmSend',
        ];
    }

    public function onHsmSend(PendingEvent $event): void
    {
        if (!$event->checkContext(self::CONTEXT)) {
            return;
        }

        $config          = $event->getEvent()->getProperties();
        $campaign        = $event->getEvent()->getCampaign();
        $router          = (string) ($config['router'] ?? '');
        $hsmId           = (string) ($config['hsmId'] ?? '');
        $status          = (string) ($config['status'] ?? 'test');
        $variablesConfig = json_decode((string) ($config['variablesJson'] ?? '{}'), true);
        $variablesConfig = is_array($variablesConfig) ? $variablesConfig : [];

        if ('test' === $status || 'paused' === $status) {
            foreach ($event->getContacts() as $logId => $contact) {
                /** @var LeadEventLog $log */
                $log = $event->getPending()->get($logId);

                $this->recordWithoutDispatch($event, $log, $contact, $campaign, $router, $hsmId, $status, $variablesConfig);
            }

            return;
        }

        $integration = $this->integrationHelper->getIntegrationObject(N8nDispatchIntegration::NAME);

        if (!$integration || !$integration->getIntegrationSettings()->isPublished()) {
            $event->failAll('N8nDispatch: the N8n Dispatch integration is not configured/enabled.');

            return;
        }

        $keys       = $integration->getKeys();
        $webhookUrl = trim((string) ($keys['webhook_url'] ?? ''));

        if ('' === $webhookUrl) {
            $event->failAll('N8nDispatch: webhook_url is not configured.');

            return;
        }

        $headers = [
            'Content-Type'          => 'application/json',
            'X-N8n-Dispatch-Action' => self::ACTION,
        ];
        $token = trim((string) ($keys['webhook_token'] ?? ''));
        if ('' !== $token) {
            $headers['X-N8n-Dispatch-Token'] = $token;
        }

        /** @var Lead $contact */
        foreach ($event->getContacts() as $logId => $contact) {
            /** @var LeadEventLog $log */
            $log = $event->getPending()->get($logId);

            $this->dispatchToContact($event, $log, $contact, $campaign, $router, $hsmId, $status, $variablesConfig, $webhookUrl, $headers);
        }
    }

    /**
     * @param array<string, array<string, mixed>> $variablesConfig
     * @param array<string, string>               $headers
     */
    private function dispatchToContact(
        PendingEvent $event,
        LeadEventLog $log,
        Lead $contact,
        Campaign $campaign,
        string $router,
        string $hsmId,
        string $status,
        array $variablesConfig,
        string $webhookUrl,
        array $headers,
    ): void {
        $phone = (string) $contact->getPhone();

        // A WhatsApp HSM has to go to a real number just as much as an
        // SMS does — same per-contact fail rather than failing the whole
        // batch, see SmsCampaignTriggerSubscriber's own version of this
        // check.
        if ('' === $phone) {
            $event->fail($log, 'N8nDispatch: contact has no phone number.');

            return;
        }

        $variables = $this->variableResolver->resolveAll($variablesConfig, $contact, $campaign);
        $payload   = $this->buildPayload($contact, $phone, $router, $hsmId, $status, $variables);

        try {
            $response = $this->httpClient->request('POST', $webhookUrl, [
                'headers' => $headers,
                'json'    => $payload,
            ]);

            // Symfony's HttpClient sends lazily — getStatusCode() has to
            // run inside the try block or a transport-level failure never
            // surfaces at all. Same requirement documented on the Email/
            // SMS dispatch listeners.
            $statusCode = $response->getStatusCode();

            $this->recordDispatchOutcome($log, $response, $payload, $statusCode);

            if ($statusCode >= 300) {
                $event->fail($log, 'N8nDispatch: '.$this->extractFailureReason($response, $statusCode));

                return;
            }

            $event->pass($log);
        } catch (\Throwable $e) {
            $this->logger->error('N8nDispatch: HSM dispatch failed for contact '.$contact->getId().': '.$e->getMessage());
            $event->fail($log, 'N8nDispatch: '.$e->getMessage());
        }
    }

    /**
     * 'test'/'paused' never reach dispatchToContact() — mirrors just the
     * payload-building half of it, so the contact's Timeline shows
     * exactly what a real dispatch would have sent, minus the actual HTTP
     * call. No phone-number check here on purpose, same reasoning as
     * SmsCampaignTriggerSubscriber's own version.
     *
     * @param array<string, array<string, mixed>> $variablesConfig
     */
    private function recordWithoutDispatch(
        PendingEvent $event,
        LeadEventLog $log,
        Lead $contact,
        Campaign $campaign,
        string $router,
        string $hsmId,
        string $status,
        array $variablesConfig,
    ): void {
        $variables = $this->variableResolver->resolveAll($variablesConfig, $contact, $campaign);
        $payload   = $this->buildPayload($contact, (string) $contact->getPhone(), $router, $hsmId, $status, $variables);

        $log->appendToMetadata(['n8ndispatch' => $payload]);

        $event->pass($log);
    }

    /**
     * @param array<string, string> $variables
     *
     * @return array<string, mixed>
     */
    private function buildPayload(Lead $contact, string $phone, string $router, string $hsmId, string $status, array $variables): array
    {
        return [
            'contact_id'                    => $contact->getId(),
            'contact_email'                 => $contact->getEmail(),
            'contact_phone'                 => $phone,
            'name'                          => $contact->getName(),
            // Same fields, same reasoning as CampaignTriggerSubscriber's
            // own buildPayload() — see that one's docblock.
            'contact_ies_id_lyceum'         => $this->variableResolver->resolveContactField($contact, 'ies_id_lyceum'),
            'contact_ies_id_company'        => $this->variableResolver->resolveContactField($contact, 'ies_id_company'),
            'contact_ies_institution_alias' => $this->variableResolver->resolveContactField($contact, 'ies_institution_alias'),
            'status'                        => $status,
            'router'                        => $router,
            'hsm_id'                        => $hsmId,
            'variables'                     => $variables,
        ];
    }

    /**
     * Same convention as CampaignTriggerSubscriber::extractFailureReason()
     * — prefers n8n's own {..., error: "..."} envelope, falls back to the
     * bare HTTP status.
     */
    private function extractFailureReason(ResponseInterface $response, int $statusCode): string
    {
        $body = json_decode($response->getContent(false), true);

        if (is_array($body) && !empty($body['error']) && is_string($body['error'])) {
            return $body['error'];
        }

        return 'webhook returned HTTP '.$statusCode.'.';
    }

    /**
     * Same convention as CampaignTriggerSubscriber::recordDispatchOutcome()
     * — records the full response and transport status on every outcome,
     * and best-effort captures n8n's own send-log id, here as
     * 'logSendHsmId' (the Email listener's own docblock already called
     * out this channel-specific sibling key by name).
     *
     * @param array<string, mixed> $payload
     */
    private function recordDispatchOutcome(LeadEventLog $log, ResponseInterface $response, array $payload, int $statusCode): void
    {
        $rawBody    = $response->getContent(false);
        $body       = json_decode($rawBody, true);
        $nestedBody = is_array($body['body'] ?? null) ? $body['body'] : [];
        $logId      = is_array($body) ? ($body['logSendHsmId'] ?? $nestedBody['logSendHsmId'] ?? null) : null;

        $n8ndispatch = $payload + [
            'response'       => is_array($body) ? $body : $rawBody,
            'httpStatusCode' => $statusCode,
        ];

        $metadata = ['n8ndispatch' => $n8ndispatch];

        if (!empty($logId)) {
            $metadata['logSendHsmId'] = $logId;
        }

        $log->appendToMetadata($metadata);
    }
}
