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
use MauticPlugin\N8nDispatchBundle\Resolver\VariableResolver;
use MauticPlugin\N8nDispatchBundle\Service\DispatchFailureReasons;
use MauticPlugin\N8nDispatchBundle\Service\DispatchOutcomeRecorder;
use MauticPlugin\N8nDispatchBundle\Service\StatusTracker;
use MauticPlugin\N8nDispatchBundle\Service\WebhookSettings;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * What the SMS and HSM "Send via n8n" campaign actions share: the step's
 * status ('test' / 'paused' only write the would-be payload to the log's
 * History, 'production' calls the webhook once per contact), the Do Not
 * Contact and phone checks, the HTTP call with its failure handling and the
 * outcome recorded on the log. A subclass says which event it handles, how
 * its step (and template) is read, what its payload looks like and which ids
 * n8n's answer may carry (see Service/DispatchOutcomeRecorder.php).
 *
 * The Email action (CampaignTriggerSubscriber) keeps its own class: it
 * registers a Stat and a template copy, which these two channels have no
 * equivalent of.
 *
 * The step (what a subclass's step() returns) is an array with at least
 * 'variablesJson' (the JSON of the variable mapping) and 'language'; the
 * rest is the channel's own (text, or router / template / type / cards) and
 * is only read by its own buildPayload().
 */
abstract class AbstractTemplateDispatchSubscriber implements EventSubscriberInterface
{
    /**
     * Joins a Custom Object variable's values when several items match, on
     * the same line (Email uses '<br>', which would be literal text in an SMS
     * or a WhatsApp message).
     */
    protected const MULTI_VALUE_SEPARATOR = ', ';

    public function __construct(
        protected IntegrationHelper $integrationHelper,
        protected HttpClientInterface $httpClient,
        protected LoggerInterface $logger,
        protected VariableResolver $variableResolver,
        protected DncModel $dncModel,
        protected DispatchFailureReasons $failureReasons,
        protected ?StatusTracker $statusTracker = null,
    ) {
    }

    /** The campaign event type this subscriber handles. */
    abstract protected function context(): string;

    /** The action n8n's webhook routes on ('sms.send'). */
    abstract protected function action(): string;

    /** The channel as written in the log of a failure ('SMS'). */
    abstract protected function label(): string;

    /** The DispatchTracking channel. */
    abstract protected function channel(): string;

    /** The ids n8n's answer may carry (see DispatchOutcomeRecorder::record()). */
    abstract protected function trackedIds(): array;

    /** Whether an empty 2xx answer is a failure (the HSM workflow does not always return a body). */
    abstract protected function emptyAnswerFails(): bool;

    /**
     * The step as it will be sent: its 'variablesJson', its 'language' and the
     * channel's own data, read from the event's properties or from the
     * template they point at. Null when the template is gone (already failed
     * for every contact).
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>|null
     */
    abstract protected function step(PendingEvent $event, array $config): ?array;

    /**
     * @param array<string, mixed>  $step
     * @param array<string, string> $variables
     *
     * @return array<string, mixed>
     */
    abstract protected function buildPayload(Lead $contact, string $phone, string $status, array $step, array $variables): array;

    protected function handle(PendingEvent $event): void
    {
        if (!$event->checkContext($this->context())) {
            return;
        }

        $config   = $event->getEvent()->getProperties();
        $campaign = $event->getEvent()->getCampaign();
        $status   = (string) ($config['status'] ?? 'test');
        $step     = $this->step($event, $config);

        if (null === $step) {
            return;
        }

        $variablesConfig = json_decode($step['variablesJson'], true);
        $variablesConfig = is_array($variablesConfig) ? $variablesConfig : [];

        if ('test' === $status || 'paused' === $status) {
            foreach ($event->getContacts() as $logId => $contact) {
                /** @var LeadEventLog $log */
                $log = $event->getPending()->get($logId);

                $this->recordWithoutDispatch($event, $log, $contact, $campaign, $status, $step, $variablesConfig);
            }

            return;
        }

        $webhook = WebhookSettings::forAction($this->integrationHelper, $this->action());

        if (is_string($webhook)) {
            $event->failAll($this->failureReasons->translated($webhook));

            return;
        }

        ['url' => $webhookUrl, 'headers' => $headers] = $webhook;

        /** @var Lead $contact */
        foreach ($event->getContacts() as $logId => $contact) {
            /** @var LeadEventLog $log */
            $log = $event->getPending()->get($logId);

            $this->dispatchToContact($event, $log, $contact, $campaign, $status, $step, $variablesConfig, $webhookUrl, $headers);
        }
    }

    /**
     * @param array<string, mixed>                $step
     * @param array<string, array<string, mixed>> $variablesConfig
     * @param array<string, string>               $headers
     */
    private function dispatchToContact(PendingEvent $event, LeadEventLog $log, Lead $contact, Campaign $campaign, string $status, array $step, array $variablesConfig, string $webhookUrl, array $headers): void
    {
        // Neither channel goes through a native Mautic send, so core's own
        // Do Not Contact check never runs: it is made here, first, before
        // resolving anything or calling n8n (see CampaignTriggerSubscriber::
        // dispatchToContact() for the full reasoning). WhatsApp has no
        // channel of its own to check against, so 'sms' is the closest real
        // proxy for both: a contact opted out of SMS is opted out of HSM too.
        if (DoNotContact::IS_CONTACTABLE !== $this->dncModel->isContactable($contact, 'sms')) {
            $event->fail($log, $this->failureReasons->translated(DispatchFailureReasons::DO_NOT_CONTACT, ['%channel%' => 'sms']));

            return;
        }

        $phone = (string) $contact->getPhone();

        // A payload with no number for n8n to dial is useless: fail this one
        // contact (the granularity core's own SmsModel::sendSms() uses for
        // 'missing_number') rather than the whole batch.
        if ('' === $phone) {
            $event->fail($log, $this->failureReasons->translated(DispatchFailureReasons::NO_PHONE));

            return;
        }

        $variables = $this->variableResolver->resolveAll($variablesConfig, $contact, $campaign, self::MULTI_VALUE_SEPARATOR, $step['language']);
        $payload   = $this->buildPayload($contact, $phone, $status, $step, $variables);

        try {
            $response = $this->httpClient->request('POST', $webhookUrl, [
                'headers' => $headers,
                'json'    => $payload,
            ]);

            // Symfony's HttpClient sends lazily — getStatusCode() has to run
            // inside the try block or a transport-level failure (DNS,
            // connection refused) never surfaces at all.
            $statusCode = $response->getStatusCode();
            $rawBody    = $response->getContent(false);
            $emptyBody  = '' === trim($rawBody);

            $this->recordDispatchOutcome($log, $rawBody, $payload, $statusCode, $this->emptyAnswerFails() && $emptyBody);

            if ($statusCode >= 300 || ($this->emptyAnswerFails() && $emptyBody)) {
                $reason = $this->emptyAnswerFails() && $emptyBody
                    ? $this->failureReasons->text(DispatchFailureReasons::EMPTY_RESPONSE, ['%action%' => $this->action()])
                    : $this->failureReasons->fromResponseBody($rawBody, $statusCode);
                $event->fail($log, $this->failureReasons->reason($reason));

                return;
            }

            $event->pass($log);
        } catch (\Throwable $e) {
            $this->logger->error('N8nDispatch: '.$this->label().' dispatch failed for contact '.$contact->getId().': '.$e->getMessage());
            $event->fail($log, $this->failureReasons->reason($e->getMessage()));
        }
    }

    /**
     * 'test'/'paused' never reach dispatchToContact(): this builds the same
     * payload, so the contact's Timeline shows exactly what a real dispatch
     * would have sent, minus the HTTP call. No phone-number check on purpose:
     * 'test' is how someone notices a contact has no number *before* flipping
     * the step to 'production'.
     *
     * @param array<string, mixed>                $step
     * @param array<string, array<string, mixed>> $variablesConfig
     */
    private function recordWithoutDispatch(PendingEvent $event, LeadEventLog $log, Lead $contact, Campaign $campaign, string $status, array $step, array $variablesConfig): void
    {
        $variables = $this->variableResolver->resolveAll($variablesConfig, $contact, $campaign, self::MULTI_VALUE_SEPARATOR, $step['language']);
        $payload   = $this->buildPayload($contact, (string) $contact->getPhone(), $status, $step, $variables);

        $log->appendToMetadata(['n8ndispatch' => $payload]);

        // Nothing is sent, but the log's History says it was a test / a pause.
        $this->statusTracker?->recordSimulated($log->getId(), $status);

        $event->pass($log);
    }

    /**
     * Records the full response and transport status on every outcome, and
     * best-effort captures n8n's own ids (see CampaignTriggerSubscriber::
     * recordDispatchOutcome()). An empty answer that counts as a failure is
     * not accepted, whatever the status.
     *
     * @param array<string, mixed> $payload
     */
    private function recordDispatchOutcome(LeadEventLog $log, string $rawBody, array $payload, int $statusCode, bool $failedEmpty): void
    {
        DispatchOutcomeRecorder::record(
            $this->statusTracker,
            $log,
            $this->channel(),
            $this->trackedIds(),
            $payload,
            $rawBody,
            $statusCode,
            $statusCode < 300 && !$failedEmpty,
        );
    }
}
