<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\EventListener;

use Mautic\CampaignBundle\Event\PendingEvent;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\DoNotContact as DncModel;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\N8nDispatchBundle\Entity\DispatchTracking;
use MauticPlugin\N8nDispatchBundle\Model\SmsTemplateModel;
use MauticPlugin\N8nDispatchBundle\N8nDispatchEvents;
use MauticPlugin\N8nDispatchBundle\Resolver\LocaleConventions;
use MauticPlugin\N8nDispatchBundle\Resolver\VariableResolver;
use MauticPlugin\N8nDispatchBundle\Service\DispatchFailureReasons;
use MauticPlugin\N8nDispatchBundle\Service\StatusTracker;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Handles the "Send via n8n (SMS)" Campaign Action — same shape as
 * CampaignTriggerSubscriber (Email), kept as its own class rather than
 * merged into it: there's no Email/Copy entity or DNC/unsubscribe variable
 * concept on this channel (a pasted textarea has no "template" to
 * register or footer to inject a link into — see
 * Form/Type/SmsDispatchActionType.php's own docblock), so the two classes
 * would mostly diverge past the shared status/dispatch/outcome shape. See
 * CampaignTriggerSubscriber's own docblock for the full 'test'/
 * 'production'/'paused' rationale; the flow it shares with HSM (status
 * branches, Do Not Contact and phone checks, the HTTP call, the outcome on
 * the log) is in AbstractTemplateDispatchSubscriber.
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
 *
 * Text and variable mapping come from the SmsTemplate the event points at
 * ('smsTemplate' property, see Entity/SmsTemplate.php), read fresh on
 * every run so an edit to the template reaches every campaign using it.
 * Events saved before templates existed have no 'smsTemplate' and still
 * carry their own inline 'text'/'variablesJson' — those keep working
 * as-is until the event is re-saved with a template picked.
 */
class SmsCampaignTriggerSubscriber extends AbstractTemplateDispatchSubscriber
{
    private const VARIABLE_PATTERN = '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/';

    public function __construct(
        IntegrationHelper $integrationHelper,
        HttpClientInterface $httpClient,
        LoggerInterface $logger,
        VariableResolver $variableResolver,
        DncModel $dncModel,
        private SmsTemplateModel $smsTemplateModel,
        DispatchFailureReasons $failureReasons,
        ?StatusTracker $statusTracker = null,
    ) {
        parent::__construct($integrationHelper, $httpClient, $logger, $variableResolver, $dncModel, $failureReasons, $statusTracker);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            N8nDispatchEvents::ON_CAMPAIGN_TRIGGER_SMS_SEND => 'onSmsSend',
        ];
    }

    public function onSmsSend(PendingEvent $event): void
    {
        $this->handle($event);
    }

    protected function context(): string
    {
        return 'n8ndispatch.sms.send';
    }

    protected function action(): string
    {
        return 'sms.send';
    }

    protected function label(): string
    {
        return 'SMS';
    }

    protected function channel(): string
    {
        return DispatchTracking::CHANNEL_SMS;
    }

    protected function trackedIds(): array
    {
        return [['body' => 'logSendSmsId', 'meta' => 'logSendSmsId', 'ref' => DispatchTracking::REF_SMS]];
    }

    protected function emptyAnswerFails(): bool
    {
        return false;
    }

    protected function step(PendingEvent $event, array $config): ?array
    {
        $text          = (string) ($config['text'] ?? '');
        $variablesJson = (string) ($config['variablesJson'] ?? '{}');
        $templateId    = (int) ($config['smsTemplate'] ?? 0);
        $language      = LocaleConventions::DEFAULT_LOCALE;

        if ($templateId > 0) {
            $template = $this->smsTemplateModel->getEntity($templateId);

            // Checked before the status branch on purpose: a deleted
            // template is a configuration error worth surfacing in 'test'
            // too, not only once the step is flipped to 'production'.
            if (null === $template) {
                $event->failAll($this->failureReasons->translated(DispatchFailureReasons::SMS_TEMPLATE_NOT_FOUND, ['%id%' => $templateId]));

                return null;
            }

            $text          = (string) $template->getText();
            $variablesJson = (string) ($template->getVariablesJson() ?? '{}');
            $language      = $template->getLanguage();
        }

        return ['text' => $text, 'variablesJson' => $variablesJson, 'language' => $language];
    }

    protected function buildPayload(Lead $contact, string $phone, string $status, array $step, array $variables): array
    {
        return [
            'contact_id'                     => $contact->getId(),
            'contact_email'                  => $contact->getEmail(),
            'contact_phone'                  => $phone,
            'contact_name'                   => $contact->getName(),
            // Same fields, same reasoning as CampaignTriggerSubscriber's
            // own buildPayload() — see that one's docblock.
            'contact_inst_id_lyceum'         => $this->variableResolver->resolveContactField($contact, 'inst_id_lyceum'),
            'contact_inst_id_company'        => $this->variableResolver->resolveContactField($contact, 'inst_id_company'),
            'contact_inst_alias'             => $this->variableResolver->resolveContactField($contact, 'inst_alias'),
            'status'                         => $status,
            // See CampaignTriggerSubscriber::buildPayload().
            'language'                       => $step['language'],
            'message'                        => $this->resolveMessage($step['text'], $variables),
            'variables'                      => $variables,
        ];
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
}
