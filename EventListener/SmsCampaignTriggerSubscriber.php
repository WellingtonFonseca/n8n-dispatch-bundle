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
 * Handles the "Send via n8n (SMS)" Campaign Action — same shape as
 * CampaignTriggerSubscriber (Email), kept as its own class rather than
 * merged into it: there's no Email/Copy entity or DNC/unsubscribe variable
 * concept on this channel (a pasted textarea has no "template" to
 * register or footer to inject a link into — see
 * Form/Type/SmsDispatchActionType.php's own docblock), so the two classes
 * would mostly diverge past the shared status/dispatch/outcome shape. See
 * CampaignTriggerSubscriber's own docblock for the full 'test'/
 * 'production'/'paused' rationale, reused here as-is.
 *
 * Difference from the Email path: the {{variable}} placeholders in the
 * pasted 'text' are resolved and substituted locally into a final
 * 'message' string before dispatch — there's no Mirror-registered
 * template on the n8n side for this channel (Email's sync-on-save step
 * has no SMS equivalent) for n8n to do that substitution against, unlike
 * Email's payload, which sends the raw {{variable}} map for n8n/Mirror to
 * resolve against its own registered template.
 *
 * No native Mautic history entity: a Mautic\SmsBundle\Entity\Stat (plus a
 * synthetic Mautic\SmsBundle\Entity\Sms row for its native Timeline
 * "view" link) was tried here and then removed on request — Email keeps
 * its own Stat because a real visual need exists there (the "view in
 * browser" / stored-copy link back to an actual template), which doesn't
 * apply to SMS's plain pasted text. The dispatch outcome still lands on
 * the contact's Timeline through this plugin's own generic
 * 'n8ndispatch'-metadata card (same as HSM, which never had a native
 * entity to begin with).
 */
class SmsCampaignTriggerSubscriber implements EventSubscriberInterface
{
    private const CONTEXT = 'n8ndispatch.sms.send';
    private const ACTION  = 'sms.send';

    private const VARIABLE_PATTERN = '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/';

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
            N8nDispatchEvents::ON_CAMPAIGN_TRIGGER_SMS_SEND => 'onSmsSend',
        ];
    }

    public function onSmsSend(PendingEvent $event): void
    {
        if (!$event->checkContext(self::CONTEXT)) {
            return;
        }

        $config          = $event->getEvent()->getProperties();
        $campaign        = $event->getEvent()->getCampaign();
        $text            = (string) ($config['text'] ?? '');
        $status          = (string) ($config['status'] ?? 'test');
        $variablesConfig = json_decode((string) ($config['variablesJson'] ?? '{}'), true);
        $variablesConfig = is_array($variablesConfig) ? $variablesConfig : [];

        if ('test' === $status || 'paused' === $status) {
            foreach ($event->getContacts() as $logId => $contact) {
                /** @var LeadEventLog $log */
                $log = $event->getPending()->get($logId);

                $this->recordWithoutDispatch($event, $log, $contact, $campaign, $text, $status, $variablesConfig);
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

            $this->dispatchToContact($event, $log, $contact, $campaign, $text, $status, $variablesConfig, $webhookUrl, $headers);
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
        string $text,
        string $status,
        array $variablesConfig,
        string $webhookUrl,
        array $headers,
    ): void {
        $phone = (string) $contact->getPhone();

        // No native transport is ever reached here (unlike core's own
        // "Send SMS" action, this always goes out via the HTTP call
        // below), but sending a payload with no number for n8n to dial is
        // just as useless — fail this one contact, same granularity core's
        // own SmsModel::sendSms() uses for its own 'missing_number' case,
        // rather than failing the whole batch over one contact's data.
        if ('' === $phone) {
            $event->fail($log, 'N8nDispatch: contact has no phone number.');

            return;
        }

        $variables = $this->variableResolver->resolveAll($variablesConfig, $contact, $campaign);
        $message   = $this->resolveMessage($text, $variables);
        $payload   = $this->buildPayload($contact, $phone, $status, $message, $variables);

        try {
            $response = $this->httpClient->request('POST', $webhookUrl, [
                'headers' => $headers,
                'json'    => $payload,
            ]);

            // Symfony's HttpClient sends lazily — getStatusCode() has to
            // run inside the try block or a transport-level failure (DNS,
            // connection refused) never surfaces at all. Same requirement
            // documented on EmailMirrorSyncSubscriber/CampaignTriggerSubscriber.
            $statusCode = $response->getStatusCode();

            $this->recordDispatchOutcome($log, $response, $payload, $statusCode);

            if ($statusCode >= 300) {
                $event->fail($log, 'N8nDispatch: '.$this->extractFailureReason($response, $statusCode));

                return;
            }

            $event->pass($log);
        } catch (\Throwable $e) {
            $this->logger->error('N8nDispatch: SMS dispatch failed for contact '.$contact->getId().': '.$e->getMessage());
            $event->fail($log, 'N8nDispatch: '.$e->getMessage());
        }
    }

    /**
     * 'test'/'paused' never reach dispatchToContact() — mirrors just the
     * payload-building half of it (see CampaignTriggerSubscriber's own
     * recordWithoutDispatch() for the full rationale), so the contact's
     * Timeline shows exactly what a real dispatch would have sent, minus
     * the actual HTTP call. No phone-number check here on purpose: 'test'
     * is precisely how someone would notice a contact has no number
     * *before* flipping the step to 'production'.
     *
     * @param array<string, array<string, mixed>> $variablesConfig
     */
    private function recordWithoutDispatch(
        PendingEvent $event,
        LeadEventLog $log,
        Lead $contact,
        Campaign $campaign,
        string $text,
        string $status,
        array $variablesConfig,
    ): void {
        $variables = $this->variableResolver->resolveAll($variablesConfig, $contact, $campaign);
        $message   = $this->resolveMessage($text, $variables);
        $payload   = $this->buildPayload($contact, (string) $contact->getPhone(), $status, $message, $variables);

        $log->appendToMetadata(['n8ndispatch' => $payload]);

        $event->pass($log);
    }

    /**
     * @param array<string, string> $variables
     */
    private function resolveMessage(string $text, array $variables): string
    {
        return (string) preg_replace_callback(
            self::VARIABLE_PATTERN,
            static fn (array $matches): string => $variables[$matches[1]] ?? $matches[0],
            $text
        );
    }

    /**
     * @param array<string, string> $variables
     *
     * @return array<string, mixed>
     */
    private function buildPayload(Lead $contact, string $phone, string $status, string $message, array $variables): array
    {
        return [
            'contact_id'                     => $contact->getId(),
            'contact_email'                  => $contact->getEmail(),
            'contact_phone'                  => $phone,
            'contact_name'                   => $contact->getName(),
            // Same fields, same reasoning as CampaignTriggerSubscriber's
            // own buildPayload() — see that one's docblock.
            'contact_ies_id_lyceum'          => $this->variableResolver->resolveContactField($contact, 'ies_id_lyceum'),
            'contact_ies_id_company'         => $this->variableResolver->resolveContactField($contact, 'ies_id_company'),
            'contact_ies_institution_alias'  => $this->variableResolver->resolveContactField($contact, 'ies_institution_alias'),
            'status'                         => $status,
            'message'                        => $message,
            'variables'                      => $variables,
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
     * 'logSendSmsId' (that method's docblock already called out this
     * channel-specific sibling key by name).
     *
     * @param array<string, mixed> $payload
     */
    private function recordDispatchOutcome(LeadEventLog $log, ResponseInterface $response, array $payload, int $statusCode): void
    {
        $rawBody    = $response->getContent(false);
        $body       = json_decode($rawBody, true);
        $nestedBody = is_array($body['body'] ?? null) ? $body['body'] : [];
        $logId      = is_array($body) ? ($body['logSendSmsId'] ?? $nestedBody['logSendSmsId'] ?? null) : null;

        $n8ndispatch = $payload + [
            'response'       => is_array($body) ? $body : $rawBody,
            'httpStatusCode' => $statusCode,
        ];

        $metadata = ['n8ndispatch' => $n8ndispatch];

        if (!empty($logId)) {
            $metadata['logSendSmsId'] = $logId;
        }

        $log->appendToMetadata($metadata);
    }
}
