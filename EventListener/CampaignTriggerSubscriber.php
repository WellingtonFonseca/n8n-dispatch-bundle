<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\EventListener;

use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\LeadEventLog;
use Mautic\CampaignBundle\Event\PendingEvent;
use Mautic\EmailBundle\Model\EmailModel;
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
 * Handles the "Send via n8n (Email)" Campaign Action, one contact at a time
 * within Mautic's batch (see CampaignSubscriber.php for why
 * batchEventName/PendingEvent, not the legacy single-event API). Only
 * actually dispatches to n8n when the step's status is 'production' —
 * 'test' records the same payload on the contact's Timeline without
 * calling out, and 'paused' skips entirely.
 */
class CampaignTriggerSubscriber implements EventSubscriberInterface
{
    private const CONTEXT = 'n8ndispatch.email.send';
    private const ACTION  = 'email.send';

    public function __construct(
        private IntegrationHelper $integrationHelper,
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private EmailModel $emailModel,
        private VariableResolver $variableResolver,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            N8nDispatchEvents::ON_CAMPAIGN_TRIGGER_EMAIL_SEND => 'onEmailSend',
        ];
    }

    public function onEmailSend(PendingEvent $event): void
    {
        if (!$event->checkContext(self::CONTEXT)) {
            return;
        }

        $config          = $event->getEvent()->getProperties();
        $campaign        = $event->getEvent()->getCampaign();
        $emailId         = (int) ($config['email'] ?? 0);
        $status          = (string) ($config['status'] ?? 'test');
        $variablesConfig = json_decode((string) ($config['variablesJson'] ?? '{}'), true);
        $variablesConfig = is_array($variablesConfig) ? $variablesConfig : [];

        if ('paused' === $status) {
            // Same "can't process right now" pattern as the checks below
            // (missing Email/integration) — Mautic reschedules a failed
            // contact automatically, so flipping the step off 'paused'
            // later picks these back up on the next campaign run, no
            // manual rebuild needed.
            $event->failAll('N8nDispatch: campaign step status is "paused", dispatch skipped.');

            return;
        }

        if ('test' === $status) {
            // No call to n8n — the point of 'test' is letting a non-technical
            // user validate the payload (merge tags resolved, right contact
            // data) straight from the contact's Timeline, without actually
            // reaching the webhook.
            foreach ($event->getContacts() as $logId => $contact) {
                /** @var LeadEventLog $log */
                $log = $event->getPending()->get($logId);

                $this->recordTestOnly($event, $log, $contact, $campaign, $emailId, $status, $variablesConfig);
            }

            return;
        }

        $email = $emailId > 0 ? $this->emailModel->getEntity($emailId) : null;

        if (null === $email) {
            $event->failAll('N8nDispatch: the configured Email template no longer exists.');

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

            $this->dispatchToContact($event, $log, $contact, $campaign, $emailId, $status, $variablesConfig, $webhookUrl, $headers);
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
        int $emailId,
        string $status,
        array $variablesConfig,
        string $webhookUrl,
        array $headers,
    ): void {
        $variables = $this->variableResolver->resolveAll($variablesConfig, $contact, $campaign);
        $payload   = $this->buildPayload($emailId, $contact, $status, $variables);

        try {
            $response = $this->httpClient->request('POST', $webhookUrl, [
                'headers' => $headers,
                'json'    => $payload,
            ]);

            // Symfony's HttpClient sends lazily — see EmailMirrorSyncSubscriber
            // for the same getStatusCode()-inside-try requirement.
            $statusCode = $response->getStatusCode();

            if ($statusCode >= 300) {
                $event->fail($log, 'N8nDispatch: '.$this->extractFailureReason($response, $statusCode));

                return;
            }

            $this->recordDispatchOutcome($log, $response, $payload);
            $event->pass($log);
        } catch (\Throwable $e) {
            $this->logger->error('N8nDispatch: dispatch failed for contact '.$contact->getId().': '.$e->getMessage());
            $event->fail($log, 'N8nDispatch: '.$e->getMessage());
        }
    }

    /**
     * 'test' status never reaches dispatchToContact() — this mirrors just
     * the payload-building half of it, so what a non-technical user sees on
     * the contact's Timeline (rendered by Resources/views/SubscribedEvents/
     * Timeline/_email_send.html.twig) is built from exactly the same fields
     * 'production' would have sent, minus the actual HTTP call.
     *
     * @param array<string, array<string, mixed>> $variablesConfig
     */
    private function recordTestOnly(
        PendingEvent $event,
        LeadEventLog $log,
        Lead $contact,
        Campaign $campaign,
        int $emailId,
        string $status,
        array $variablesConfig,
    ): void {
        $variables = $this->variableResolver->resolveAll($variablesConfig, $contact, $campaign);
        $payload   = $this->buildPayload($emailId, $contact, $status, $variables);

        $log->appendToMetadata(['n8ndispatch' => $payload]);

        $event->pass($log);
    }

    /**
     * @param array<string, mixed> $variables
     *
     * @return array<string, mixed>
     */
    private function buildPayload(int $emailId, Lead $contact, string $status, array $variables): array
    {
        return [
            'mautic_template_id' => $emailId,
            'contact_id'         => $contact->getId(),
            'contact_email'      => $contact->getEmail(),
            'status'             => $status,
            'variables'          => $variables,
        ];
    }

    /**
     * Prefers the human-readable message n8n's workflow puts in the response
     * body's 'error' field (e.g. "code 404, Entity not found - contact") so
     * it shows up as-is on the contact's Timeline in the Mautic UI — falls
     * back to the plain HTTP status code if the body isn't JSON or doesn't
     * have that field, so a webhook that doesn't follow this convention
     * still fails with a useful-enough reason instead of a parse error.
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
     * n8n's workflow returns the id of the send record it created in Mirror
     * as 'logSendEmailId' on a successful dispatch — 'logSendEmailId'
     * because this listener only ever handles the Email channel; a future
     * SMS/HSM trigger listener would read its own 'logSendSmsId'/
     * 'logSendHsmId' sibling instead. Checked at both the top level and
     * one level under 'body' — n8n's "Respond to Webhook" node here
     * forwards the Mirror HTTP node's own raw output as-is, which nests
     * the actual payload one level down (e.g. {body: {logSendEmailId},
     * headers, statusCode, statusMessage}); the flat top-level check is
     * kept too in case that wiring changes later. Stored on the
     * LeadEventLog's own metadata (survives PendingEvent::pass(), which
     * only strips its own 'errors'/'failed'/'reason' keys) purely for
     * traceability back to the matching record in Mirror — never
     * required, missing/malformed just means no id gets attached, the
     * dispatch is still a pass either way.
     *
     * Also writes metadata['n8ndispatch'] — the same payload shape 'test'
     * status shows via recordTestOnly(), plus the raw response body — so
     * Resources/views/SubscribedEvents/Timeline/_email_send.html.twig (this
     * event type's registered 'timelineTemplate', see CampaignSubscriber)
     * can render a card on the contact's Timeline showing exactly what was
     * sent and what came back, not just that something was sent.
     *
     * @param array<string, mixed> $payload
     */
    private function recordDispatchOutcome(LeadEventLog $log, ResponseInterface $response, array $payload): void
    {
        $rawBody    = $response->getContent(false);
        $body       = json_decode($rawBody, true);
        $nestedBody = is_array($body['body'] ?? null) ? $body['body'] : [];
        $logId      = is_array($body) ? ($body['logSendEmailId'] ?? $nestedBody['logSendEmailId'] ?? null) : null;

        $metadata = [
            // 'response' is the raw decoded body (falling back to the raw
            // string when it isn't valid JSON) — the Timeline card just
            // dumps it as-is, so if Mirror's response shape grows new
            // fields later, they show up there automatically, no template
            // change needed.
            'n8ndispatch' => $payload + ['response' => is_array($body) ? $body : $rawBody],
        ];

        if (!empty($logId)) {
            $metadata['logSendEmailId'] = $logId;
        }

        $log->appendToMetadata($metadata);
    }
}
