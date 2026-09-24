<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\EventListener;

use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\LeadEventLog;
use Mautic\CampaignBundle\Event\PendingEvent;
use Mautic\LeadBundle\Entity\DoNotContact;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\DoNotContact as DncModel;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\N8nDispatchBundle\Entity\HsmTemplate;
use MauticPlugin\N8nDispatchBundle\Integration\N8nDispatchIntegration;
use MauticPlugin\N8nDispatchBundle\Model\HsmTemplateModel;
use MauticPlugin\N8nDispatchBundle\N8nDispatchEvents;
use MauticPlugin\N8nDispatchBundle\Resolver\VariableResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Handles the "Send via n8n (HSM)" Campaign Action — same
 * status/dispatch/outcome shape as CampaignTriggerSubscriber (Email) and
 * SmsCampaignTriggerSubscriber (SMS), see either one's own docblock for
 * the full 'test'/'production'/'paused' rationale, reused here as-is.
 *
 * Differences from both of those:
 * - No message text sent anywhere — HSM has no Mautic-side template
 *   content, WhatsApp already has the real template on its side. But
 *   'router', 'hsmTemplate' (the WhatsApp-side template descriptor
 *   itself, e.g. "lembrete_aula_v1" — not to be confused with
 *   'hsmTemplateId' below, this listener's own local variable naming
 *   follows the entity's Entity/HsmTemplate.php::getHsmTemplate()),
 *   'type' (WhatsApp send shape — text, image, carousel, ...; see that
 *   entity's own TYPE_TEXT docblock), and 'variables' (resolved from the
 *   template's own {{name}}-mapped 'variablesJson', same convention
 *   Email/SMS use — see that entity's own docblock for why it carries a
 *   'text' field despite never sending it) all come from the HsmTemplate
 *   the event points at ('hsmTemplateId' property — which HsmTemplate
 *   entity a campaign picked, an int, set by Form/Type/
 *   HsmDispatchActionType.php's own 'hsmTemplateId' field), read fresh
 *   on every run so an edit to the template reaches every campaign using
 *   it — same reasoning as SmsCampaignTriggerSubscriber's own move.
 *   Events saved before templates existed have no 'hsmTemplateId' and
 *   still carry their own inline 'router'/'hsmId'/'variablesJson' (the
 *   inline property kept its original pre-rename key, 'hsmId' — it's
 *   historical serialized data already on disk, nothing to gain from
 *   renaming it retroactively; and, since 'type' never existed as an
 *   inline field, always default to HsmTemplate::TYPE_TEXT) — those keep
 *   working as-is until the event is re-saved with a template picked.
 *   Replaces the positional ($1, $2, ...) variable picker this action's
 *   own form used to have, dropped when router/hsmTemplate/variables all
 *   moved onto the template.
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

    // Joins a Custom Object variable's values when several items match —
    // same reasoning as SmsCampaignTriggerSubscriber's own constant:
    // WhatsApp messages are plain text too, so '<br>' would show up
    // literally.
    private const MULTI_VALUE_SEPARATOR = ', ';

    public function __construct(
        private IntegrationHelper $integrationHelper,
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private VariableResolver $variableResolver,
        private DncModel $dncModel,
        private HsmTemplateModel $hsmTemplateModel,
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

        $config        = $event->getEvent()->getProperties();
        $campaign      = $event->getEvent()->getCampaign();
        $status        = (string) ($config['status'] ?? 'test');
        $router        = (string) ($config['router'] ?? '');
        $hsmTemplate   = (string) ($config['hsmId'] ?? '');
        $hsmType       = HsmTemplate::TYPE_TEXT;
        $variablesJson = (string) ($config['variablesJson'] ?? '{}');
        $templateId    = (int) ($config['hsmTemplateId'] ?? 0);

        if ($templateId > 0) {
            $template = $this->hsmTemplateModel->getEntity($templateId);

            // Checked before the status branch on purpose: a deleted
            // template is a configuration error worth surfacing in 'test'
            // too, not only once the step is flipped to 'production'.
            if (null === $template) {
                $event->failAll('N8nDispatch: HSM template #'.$templateId.' not found.');

                return;
            }

            $router        = (string) $template->getRouter();
            $hsmTemplate   = (string) $template->getHsmTemplate();
            $hsmType       = $template->getType();
            $variablesJson = (string) ($template->getVariablesJson() ?? '{}');
        }

        $variablesConfig = json_decode($variablesJson, true);
        $variablesConfig = is_array($variablesConfig) ? $variablesConfig : [];

        if ('test' === $status || 'paused' === $status) {
            foreach ($event->getContacts() as $logId => $contact) {
                /** @var LeadEventLog $log */
                $log = $event->getPending()->get($logId);

                $this->recordWithoutDispatch($event, $log, $contact, $campaign, $router, $hsmTemplate, $hsmType, $status, $variablesConfig);
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

            $this->dispatchToContact($event, $log, $contact, $campaign, $router, $hsmTemplate, $hsmType, $status, $variablesConfig, $webhookUrl, $headers);
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
        string $hsmTemplate,
        string $hsmType,
        string $status,
        array $variablesConfig,
        string $webhookUrl,
        array $headers,
    ): void {
        // Same DNC gap as the Email/SMS dispatch paths (see
        // CampaignTriggerSubscriber::dispatchToContact() for the full
        // reasoning). HSM/WhatsApp has no native Mautic channel of its own
        // to check against, so this reuses the 'sms' channel as the closest
        // real proxy — a contact opted out of SMS is treated as opted out
        // of HSM too, on request.
        if (DoNotContact::IS_CONTACTABLE !== $this->dncModel->isContactable($contact, 'sms')) {
            $event->fail($log, 'N8nDispatch: contact is on the Do Not Contact list for sms.');

            return;
        }

        $phone = (string) $contact->getPhone();

        // A WhatsApp HSM has to go to a real number just as much as an
        // SMS does — same per-contact fail rather than failing the whole
        // batch, see SmsCampaignTriggerSubscriber's own version of this
        // check.
        if ('' === $phone) {
            $event->fail($log, 'N8nDispatch: contact has no phone number.');

            return;
        }

        $variables = $this->variableResolver->resolveAll($variablesConfig, $contact, $campaign, self::MULTI_VALUE_SEPARATOR);
        $payload   = $this->buildPayload($contact, $phone, $router, $hsmTemplate, $hsmType, $status, $variables);

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
            $rawBody    = $response->getContent(false);

            $this->recordDispatchOutcome($log, $rawBody, $payload, $statusCode);

            // Unlike Email/SMS, n8n's workflow side for this channel
            // doesn't reliably return a real body yet (confirmed live: a
            // 200 with an empty body, meaning the request landed but
            // nothing indicates whether a WhatsApp send actually
            // happened) — until it does, an empty response is treated as
            // a failure too, not just statusCode >= 300, on request.
            if ($statusCode >= 300 || '' === trim($rawBody)) {
                $reason = '' === trim($rawBody)
                    ? 'n8n returned an empty response for hsm.send.'
                    : $this->extractFailureReason($rawBody, $statusCode);
                $event->fail($log, 'N8nDispatch: '.$reason);

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
        string $hsmTemplate,
        string $hsmType,
        string $status,
        array $variablesConfig,
    ): void {
        $variables = $this->variableResolver->resolveAll($variablesConfig, $contact, $campaign, self::MULTI_VALUE_SEPARATOR);
        $payload   = $this->buildPayload($contact, (string) $contact->getPhone(), $router, $hsmTemplate, $hsmType, $status, $variables);

        $log->appendToMetadata(['n8ndispatch' => $payload]);

        $event->pass($log);
    }

    /**
     * @param array<string, string> $variables
     *
     * @return array<string, mixed>
     */
    private function buildPayload(Lead $contact, string $phone, string $router, string $hsmTemplate, string $hsmType, string $status, array $variables): array
    {
        return [
            'contact_id'                    => $contact->getId(),
            'contact_email'                 => $contact->getEmail(),
            'contact_phone'                 => $phone,
            'contact_name'                  => $contact->getName(),
            // Same fields, same reasoning as CampaignTriggerSubscriber's
            // own buildPayload() — see that one's docblock.
            'contact_inst_id_lyceum'        => $this->variableResolver->resolveContactField($contact, 'inst_id_lyceum'),
            'contact_inst_id_company'       => $this->variableResolver->resolveContactField($contact, 'inst_id_company'),
            'contact_inst_alias'            => $this->variableResolver->resolveContactField($contact, 'inst_alias'),
            'status'                        => $status,
            'hsm_router'                    => $router,
            'hsm_template'                  => $hsmTemplate,
            'hsm_type'                      => $hsmType,
            'variables'                     => $variables,
        ];
    }

    /**
     * Same convention as CampaignTriggerSubscriber::extractFailureReason()
     * — prefers n8n's own {..., error: "..."} envelope, falls back to the
     * bare HTTP status.
     */
    private function extractFailureReason(string $rawBody, int $statusCode): string
    {
        $body = json_decode($rawBody, true);

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
    private function recordDispatchOutcome(LeadEventLog $log, string $rawBody, array $payload, int $statusCode): void
    {
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
