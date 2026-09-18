<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\LeadEventLog;
use Mautic\CampaignBundle\Event\PendingEvent;
use Mautic\EmailBundle\Entity\Copy;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Entity\Stat;
use Mautic\EmailBundle\Helper\MailHashHelper;
use Mautic\EmailBundle\Model\EmailModel;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\N8nDispatchBundle\Integration\N8nDispatchIntegration;
use MauticPlugin\N8nDispatchBundle\N8nDispatchEvents;
use MauticPlugin\N8nDispatchBundle\Resolver\VariableResolver;
use MauticPlugin\N8nDispatchBundle\UnsubscribeVariable;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Handles the "Send via n8n (Email)" Campaign Action, one contact at a time
 * within Mautic's batch (see CampaignSubscriber.php for why
 * batchEventName/PendingEvent, not the legacy single-event API). Only
 * actually dispatches to n8n when the step's status is 'production' —
 * 'test' and 'paused' both just record the payload that would have been
 * sent on the contact's Timeline, without calling out. They're handled
 * identically; the Timeline card (see recordWithoutDispatch()) only
 * differs by badge color/label, driven by the 'status' value itself.
 * 'paused' going through here — instead of failing the step — also means
 * a paused period now leaves a permanent, visible record on the Timeline,
 * unlike the old failAll() path whose failure metadata got wiped by
 * PendingEvent::pass() the moment the contact was later dispatched for
 * real.
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
        private MailHashHelper $mailHashHelper,
        private EntityManagerInterface $entityManager,
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

        if ('test' === $status || 'paused' === $status) {
            // No call to n8n — 'test' lets a non-technical user validate the
            // payload (merge tags resolved, right contact data) straight
            // from the contact's Timeline; 'paused' means the step is
            // deliberately held back. Either way nothing is sent, so both
            // just record what would have gone out.
            foreach ($event->getContacts() as $logId => $contact) {
                /** @var LeadEventLog $log */
                $log = $event->getPending()->get($logId);

                $this->recordWithoutDispatch($event, $log, $contact, $campaign, $emailId, $status, $variablesConfig);
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

        // Only worth doing for a real dispatch — 'test'/'paused' never
        // reach here, so there's no "proof of what was sent" need for them.
        $templateCopyHash = $this->saveTemplateCopy($email);

        /** @var Lead $contact */
        foreach ($event->getContacts() as $logId => $contact) {
            /** @var LeadEventLog $log */
            $log = $event->getPending()->get($logId);

            $this->dispatchToContact($event, $log, $contact, $campaign, $emailId, $email, $status, $variablesConfig, $webhookUrl, $headers, $templateCopyHash);
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
        Email $email,
        string $status,
        array $variablesConfig,
        string $webhookUrl,
        array $headers,
        ?string $templateCopyHash,
    ): void {
        $variables    = $this->variableResolver->resolveAll($variablesConfig, $contact, $campaign);
        $contactEmail = (string) $contact->getEmail();
        // Generated up front so the same value both goes out in this
        // dispatch's payload and (only on confirmed success, see below)
        // becomes the real Stat's trackingHash — but no Stat is created
        // yet. A failed dispatch never actually reached the contact, so it
        // must not look like it did in core's native Sent-Email Timeline
        // entry/history, which is driven entirely by Stat rows existing.
        $idHash                              = str_replace('.', '', uniqid('', true));
        $variables[UnsubscribeVariable::KEY] = $this->buildUnsubscribeUrl($contactEmail, $idHash);
        $payload                             = $this->buildPayload($emailId, $contact, $status, $variables);

        try {
            $response = $this->httpClient->request('POST', $webhookUrl, [
                'headers' => $headers,
                'json'    => $payload,
            ]);

            // Symfony's HttpClient sends lazily — see EmailMirrorSyncSubscriber
            // for the same getStatusCode()-inside-try requirement.
            $statusCode = $response->getStatusCode();

            // Recorded before the pass/fail branch below, and on every
            // outcome — not just success: PendingEvent::fail() only
            // array_merge()s 'failed'/'reason' on top of the log's existing
            // metadata (confirmed by reading its source), it never clears
            // what's already there, so the full n8ndispatch response
            // (headers/body/statusCode — see recordDispatchOutcome() below)
            // survives into a failed log's metadata too. A single bare HTTP
            // status code or the trimmed extractFailureReason() string
            // wasn't enough to diagnose a real failure (a 404 alone doesn't
            // say whether it's a missing template, missing contact, or bad
            // variables) — Resources/views/SubscribedEvents/Timeline/
            // _email_send.html.twig already renders the same Body/Response
            // JSON blocks and outcome badge regardless of pass/fail, once
            // this metadata exists.
            $this->recordDispatchOutcome($log, $response, $payload, $templateCopyHash, $statusCode);

            if ($statusCode >= 300) {
                $event->fail($log, 'N8nDispatch: '.$this->extractFailureReason($response, $statusCode));

                return;
            }

            // Only now, on Mirror's own confirmed success, register the
            // Stat that backs core's native Sent-Email Timeline entry and
            // the DNC/unsubscribe route — see createStat()'s own docblock.
            $this->createStat($email, $contact, $contactEmail, $idHash, $templateCopyHash);

            $event->pass($log);
        } catch (\Throwable $e) {
            $this->logger->error('N8nDispatch: dispatch failed for contact '.$contact->getId().': '.$e->getMessage());
            $event->fail($log, 'N8nDispatch: '.$e->getMessage());
        }
    }

    /**
     * 'test'/'paused' never reach dispatchToContact() — this mirrors just
     * the payload-building half of it, so what a non-technical user sees on
     * the contact's Timeline (rendered by Resources/views/SubscribedEvents/
     * Timeline/_email_send.html.twig) is built from exactly the same fields
     * 'production' would have sent, minus the actual HTTP call. $status is
     * 'test' or 'paused' here and ends up in the payload/metadata as-is —
     * the Timeline card reads it to pick the badge color/label.
     *
     * @param array<string, array<string, mixed>> $variablesConfig
     */
    private function recordWithoutDispatch(
        PendingEvent $event,
        LeadEventLog $log,
        Lead $contact,
        Campaign $campaign,
        int $emailId,
        string $status,
        array $variablesConfig,
    ): void {
        $variables                            = $this->variableResolver->resolveAll($variablesConfig, $contact, $campaign);
        $variables[UnsubscribeVariable::KEY] = '(not generated — no real dispatch)';
        $payload                              = $this->buildPayload($emailId, $contact, $status, $variables);

        $log->appendToMetadata(['n8ndispatch' => $payload]);

        $event->pass($log);
    }

    /**
     * Pure URL generation — EmailModel::buildUrl() just resolves a Symfony
     * route, it doesn't touch the database or require a Stat to already
     * exist. A Stat matching this exact $idHash only gets created later,
     * by createStat(), and only if the dispatch actually succeeds — see
     * that method's docblock for why the two are deliberately split apart.
     */
    private function buildUnsubscribeUrl(string $contactEmail, string $idHash): string
    {
        return (string) $this->emailModel->buildUrl('mautic_email_unsubscribe', [
            'idHash'     => $idHash,
            'urlEmail'   => $contactEmail,
            'secretHash' => $this->mailHashHelper->getEmailHash($contactEmail),
        ]);
    }

    /**
     * Closes the DNC/unsubscribe compliance gap this whole dispatch path
     * otherwise has: since we never go through Mautic's own mailer, none
     * of the Stat rows core's native "Send Email" relies on ever get
     * created, so its /email/unsubscribe/... link — and RFC 8058 One-Click
     * Unsubscribe support behind it (PublicController::unsubscribeAction) —
     * never has anything to resolve. Creating that one Stat row ourselves,
     * via the same public EmailModel::saveEmailStat() core itself calls,
     * is enough to make that entire existing, unmodified route work for a
     * contact who got here through n8n instead.
     *
     * Only called from dispatchToContact() after a confirmed 2xx from
     * Mirror/n8n — a Stat is also exactly what makes core's native
     * Sent-Email Timeline entry appear for a contact (EmailBundle's
     * LeadSubscriber reads the same email_stats table this dispatch path
     * otherwise never touches), so creating one on a failed dispatch would
     * make a contact's history show "email sent" for an email that never
     * actually went out. The idHash used here is the exact one already
     * embedded in the unsubscribe URL sent in this same request's payload
     * (see buildUnsubscribeUrl()) — generated before the HTTP call (so it
     * could be included in it), but only turned into a real, persisted Stat
     * once we know Mirror actually accepted the send.
     *
     * Also links the Stat to the same Copy row saveTemplateCopy() already
     * snapshotted for this batch, via setStoredCopy() — the same field
     * core's own MailHelper::createEmailStat() populates on a real send.
     * Without it, core's native "Sent Email" Timeline entry links to
     * mautic_email_webview/PublicController::indexAction, which reads
     * $stat->getStoredCopy() to render anything at all — empty otherwise,
     * so the contact's "view in browser" link opened a blank page.
     * getReference() (a Doctrine proxy by id, no extra query — the exact
     * pattern MailHelper itself uses for this same field) needs a plain
     * EntityManagerInterface: CopyRepository::getEntityManager() exists
     * but is protected (Doctrine's own base EntityRepository), so this
     * plugin injects EntityManagerInterface directly instead, same as any
     * other Symfony service.
     */
    private function createStat(Email $email, Lead $contact, string $contactEmail, string $idHash, ?string $templateCopyHash): void
    {
        $stat = new Stat();
        $stat->setEmail($email);
        $stat->setLead($contact);
        $stat->setEmailAddress($contactEmail);
        $stat->setTrackingHash($idHash);
        $stat->setDateSent(new \DateTime());

        if (null !== $templateCopyHash) {
            $stat->setStoredCopy($this->entityManager->getReference(Copy::class, $templateCopyHash));
        }

        $this->emailModel->saveEmailStat($stat);
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
     * Snapshots the Email template's raw content (subject/HTML/plain text,
     * no token substitution — proof of what template was configured at
     * dispatch time, not what a specific contact received) by reusing
     * Mautic core's own EmailBundle\Entity\Copy/CopyRepository — the exact
     * mechanism core's own MailHelper::createEmailStat() uses to back the
     * "view in browser" link, keyed by an MD5 hash of subject+body so
     * identical template content is stored once regardless of how many
     * times it's dispatched. No core file is touched; this only calls
     * public repository methods already exposed via
     * EmailModel::getCopyRepository(). Returns null if the row couldn't be
     * found or created, in which case the Timeline simply won't show a
     * "view template" link.
     */
    private function saveTemplateCopy(Email $email): ?string
    {
        $subject = (string) $email->getSubject();
        $body    = (string) $email->getCustomHtml();
        $hash    = md5($subject.$body);

        $copyRepository = $this->emailModel->getCopyRepository();

        if (null !== $copyRepository->findByHash($hash)) {
            return $hash;
        }

        return $copyRepository->saveCopy($hash, $subject, $body, (string) $email->getPlainText()) ? $hash : null;
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
     * Called on every outcome (success or failure — see dispatchToContact(),
     * this runs before the statusCode >= 300 branch decides pass()/fail()),
     * not just a successful dispatch. A bare HTTP status code or the
     * trimmed extractFailureReason() string isn't enough to actually
     * diagnose a real failure — a 404 alone doesn't say whether it's a
     * missing template, a missing contact, or bad variables — so the full
     * response (headers/body/statusCode) needs to reach the Timeline
     * either way, not just on a pass.
     *
     * Writes metadata['n8ndispatch'] — the same payload shape
     * recordWithoutDispatch() shows for 'test'/'paused', plus the raw
     * response body — so
     * Resources/views/SubscribedEvents/Timeline/_email_send.html.twig (this
     * event type's registered 'timelineTemplate', see CampaignSubscriber)
     * can render a card on the contact's Timeline showing exactly what was
     * sent and what came back, not just that something was sent. Safe to
     * call regardless of status: $response->getContent(false) never throws
     * on a non-2xx status, unlike the argument-less getContent().
     *
     * $statusCode (the real HTTP transport status — the same value
     * dispatchToContact() itself branches pass()/fail() on) is stored as
     * its own 'httpStatusCode' key, separate from the response body's own
     * nested 'statusCode' field (n8n's convention wraps Mirror's result as
     * {statusCode, statusMessage, error, body}). The Timeline card's
     * outcome badge reads 'httpStatusCode' specifically, not the nested
     * one — the two are supposed to agree by n8n-side convention, but
     * they're two different signals from two different layers, and only
     * one of them is what this listener's own pass/fail decision actually
     * used. Confirmed live: with a test workflow that changed the outer
     * HTTP status to 2xx without updating the mocked body's own nested
     * statusCode to match, keying the badge off the body field showed a
     * false "Failed" for a dispatch that had, by every measure this code
     * itself cares about, succeeded.
     *
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
     * only strips its own 'errors'/'failed'/'reason' keys, and
     * PendingEvent::fail(), which only array_merge()s 'failed'/'reason' on
     * top — confirmed by reading both) purely for traceability back to the
     * matching record in Mirror — never required, a failed dispatch simply
     * won't have one, since nothing actually got sent.
     *
     * @param array<string, mixed> $payload
     */
    private function recordDispatchOutcome(LeadEventLog $log, ResponseInterface $response, array $payload, ?string $templateCopyHash, int $statusCode): void
    {
        $rawBody    = $response->getContent(false);
        $body       = json_decode($rawBody, true);
        $nestedBody = is_array($body['body'] ?? null) ? $body['body'] : [];
        $logId      = is_array($body) ? ($body['logSendEmailId'] ?? $nestedBody['logSendEmailId'] ?? null) : null;

        $n8ndispatch = $payload + [
            // 'response' is the raw decoded body (falling back to the raw
            // string when it isn't valid JSON) — the Timeline card just
            // dumps it as-is, so if Mirror's response shape grows new
            // fields later, they show up there automatically, no template
            // change needed.
            'response'       => is_array($body) ? $body : $rawBody,
            'httpStatusCode' => $statusCode,
        ];

        if (null !== $templateCopyHash) {
            $n8ndispatch['templateCopyHash'] = $templateCopyHash;
        }

        $metadata = ['n8ndispatch' => $n8ndispatch];

        if (!empty($logId)) {
            $metadata['logSendEmailId'] = $logId;
        }

        $log->appendToMetadata($metadata);
    }
}
