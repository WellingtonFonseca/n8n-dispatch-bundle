<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\LeadEventLog;
use Mautic\CampaignBundle\Event\PendingEvent;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use Mautic\SmsBundle\Entity\Sms;
use Mautic\SmsBundle\Entity\Stat;
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
 * Two differences from the Email path worth calling out:
 * - The {{variable}} placeholders in the pasted 'text' are resolved and
 *   substituted locally into a final 'message' string before dispatch —
 *   there's no Mirror-registered template on the n8n side for this
 *   channel (Email's sync-on-save step has no SMS equivalent) for n8n to
 *   do that substitution against, unlike Email's payload, which sends the
 *   raw {{variable}} map for n8n/Mirror to resolve against its own
 *   registered template.
 * - Uses Mautic\SmsBundle\Entity\Stat (sms_message_stats) instead of
 *   EmailBundle's, so a dispatch shows up in the contact's native
 *   Timeline the same way a core "Send SMS" campaign action's own send
 *   would (SmsBundle\EventListener\LeadSubscriber reads this same table).
 *   That native Timeline entry's own "view" link only resolves if the
 *   Stat's 'sms' field points at a real Mautic\SmsBundle\Entity\Sms row
 *   (confirmed live: left null, the link just goes to the bare SMS list
 *   screen instead of the message — no exception, but not useful either).
 *   resolveSmsTemplate() creates/reuses one lightweight Sms row per
 *   distinct pasted text (deduped by exact message match, same idea as
 *   CampaignTriggerSubscriber's Copy-row dedup for Email), left
 *   unpublished so it never shows up as pickable in core's own "Send SMS"
 *   action's Sms list — it exists purely so this dispatch path's native
 *   Timeline link has something real to open, not as a reusable template.
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
        private EntityManagerInterface $entityManager,
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

        // Resolved once per batch, not per contact — every contact in this
        // batch is dispatching the exact same configured 'text', so a
        // per-contact lookup/create would just repeat the same DB
        // round-trip for no benefit.
        $smsEntity = $this->resolveSmsTemplate($text);

        /** @var Lead $contact */
        foreach ($event->getContacts() as $logId => $contact) {
            /** @var LeadEventLog $log */
            $log = $event->getPending()->get($logId);

            $this->dispatchToContact($event, $log, $contact, $campaign, $text, $status, $variablesConfig, $webhookUrl, $headers, $smsEntity);
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
        Sms $smsEntity,
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
                $this->createStat($contact, $event->getEvent()->getId(), $smsEntity, true);
                $event->fail($log, 'N8nDispatch: '.$this->extractFailureReason($response, $statusCode));

                return;
            }

            $this->createStat($contact, $event->getEvent()->getId(), $smsEntity, false);

            $event->pass($log);
        } catch (\Throwable $e) {
            $this->createStat($contact, $event->getEvent()->getId(), $smsEntity, true);
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
     * Creates the native Mautic\SmsBundle\Entity\Stat row for this
     * dispatch (sms_message_stats), the SMS-channel equivalent of what
     * CampaignTriggerSubscriber's createStat() does for Email — see this
     * class's own docblock for why 'sms' is left null. 'source'/
     * 'sourceId' mirror the convention core's own SmsBundle\Model\
     * SmsModel::createStatEntry() uses for a campaign-triggered send
     * ($channel = ['campaign.event', $eventId]), so a future admin
     * querying sms_message_stats by source/source_id finds this dispatch
     * the same way they'd find a native one.
     *
     * Uses the entity manager directly (persist + flush) rather than a
     * model/repository save method — SmsModel's own createStatEntry()
     * requires a real Sms entity as its first argument, which doesn't
     * exist on this channel, so it can't be reused here the way Email's
     * EmailModel::saveEmailStat() could.
     */
    private function createStat(Lead $contact, ?int $campaignEventId, Sms $smsEntity, bool $isFailed): void
    {
        $stat = new Stat();
        $stat->setSms($smsEntity);
        $stat->setLead($contact);
        $stat->setDateSent(new \DateTime());
        $stat->setIsFailed($isFailed);
        $stat->setSource('campaign.event');
        $stat->setSourceId($campaignEventId);
        $stat->setTrackingHash(str_replace('.', '', uniqid('', true)));

        $this->entityManager->persist($stat);
        $this->entityManager->flush();
    }

    /**
     * Creates/reuses the lightweight Sms row a Stat needs so core's native
     * Timeline "view" link resolves to something real — see this class's
     * own docblock for why. Deduped by an exact match on 'message' (the
     * raw pasted text, {{variable}} placeholders and all — the same
     * template text every contact in this batch dispatches, not each
     * contact's own resolved message) via a plain findOneBy(), so
     * re-triggering the same campaign event over and over doesn't pile up
     * a new row every time — only the first dispatch of a given text ever
     * creates one.
     *
     * Left unpublished deliberately: core's own "Send SMS" campaign
     * action/Sms pickers filter to published rows, so this stays
     * invisible there — it's not meant to be reused as a real template,
     * only to exist for this dispatch path's own Stat to point at.
     */
    private function resolveSmsTemplate(string $text): Sms
    {
        /** @var Sms|null $sms */
        $sms = $this->entityManager->getRepository(Sms::class)->findOneBy(['message' => $text]);

        if (null !== $sms) {
            return $sms;
        }

        $name = 'N8n Dispatch: '.mb_substr(trim($text), 0, 80);

        $sms = new Sms();
        $sms->setName('' !== $name ? $name : 'N8n Dispatch SMS');
        $sms->setMessage($text);
        $sms->setIsPublished(false);

        $this->entityManager->persist($sms);
        $this->entityManager->flush();

        return $sms;
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
            'name'                           => $contact->getName(),
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
