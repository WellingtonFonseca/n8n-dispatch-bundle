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

/**
 * Fires the actual dispatch to n8n for the "Send via n8n (Email)" Campaign
 * Action, one contact at a time within Mautic's batch (see
 * CampaignSubscriber.php for why batchEventName/PendingEvent, not the
 * legacy single-event API).
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
        $status          = (string) ($config['status'] ?? 'teste');
        $variablesConfig = json_decode((string) ($config['variablesJson'] ?? '{}'), true);
        $variablesConfig = is_array($variablesConfig) ? $variablesConfig : [];

        if ('pausado' === $status) {
            // Same "can't process right now" pattern as the checks below
            // (missing Email/integration) — Mautic reschedules a failed
            // contact automatically, so flipping the step off 'pausado'
            // later picks these back up on the next campaign run, no
            // manual rebuild needed.
            $event->failAll('N8nDispatch: campaign step status is "pausado", dispatch skipped.');

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

        try {
            $response = $this->httpClient->request('POST', $webhookUrl, [
                'headers' => $headers,
                'json'    => [
                    'mautic_template_id' => $emailId,
                    'contact_id'         => $contact->getId(),
                    'contact_email'      => $contact->getEmail(),
                    'status'             => $status,
                    'variables'          => $variables,
                ],
            ]);

            // Symfony's HttpClient sends lazily — see EmailMirrorSyncSubscriber
            // for the same getStatusCode()-inside-try requirement.
            $statusCode = $response->getStatusCode();

            if ($statusCode >= 300) {
                $event->fail($log, 'N8nDispatch: webhook returned HTTP '.$statusCode.'.');

                return;
            }

            $event->pass($log);
        } catch (\Throwable $e) {
            $this->logger->error('N8nDispatch: dispatch failed for contact '.$contact->getId().': '.$e->getMessage());
            $event->fail($log, 'N8nDispatch: '.$e->getMessage());
        }
    }
}
