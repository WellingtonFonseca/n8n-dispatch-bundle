<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\LeadEventLog;
use Mautic\CampaignBundle\Event\PendingEvent;
use Mautic\EmailBundle\Entity\Email;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\N8nDispatchBundle\Resolver\VariableResolver;

/**
 * The Email of a production step sent straight through Mautic's mailer (see DirectEmailSender), for an instance whose
 * Email send mode is 'mautic'. No webhook, no Stat of ours and no template copy: the mailer makes its own, which is
 * what core's tracking, "view in browser" and unsubscribe read. The log keeps what went out and the id of that Stat,
 * so the Dispatches screen can read the result from Mautic itself.
 */
class DirectEmailDispatch
{
    /**
     * Consecutive sends through Mautic's mailer that failed with the very same error: past this the rest of the batch is
     * not tried (see dispatch()).
     */
    private const STOP_AFTER = 3;

    public function __construct(
        private DirectEmailSender $sender,
        private VariableResolver $variableResolver,
        private DispatchFailureReasons $failureReasons,
        private EmailDncGuard $dncGuard,
    ) {
    }

    public function isOn(): bool
    {
        return $this->sender->isOn();
    }

    /**
     * @param array<string, array<string, mixed>> $variablesConfig
     */
    public function dispatch(PendingEvent $event, Campaign $campaign, int $emailId, Email $email, string $status, array $variablesConfig, string $language): void
    {
        // Core's own action does not send an unpublished Email either; failing (not passing) lets the step go
        // out once the Email is published again.
        if (!$email->isPublished()) {
            $event->failAll($this->failureReasons->translated(DispatchFailureReasons::EMAIL_NOT_PUBLISHED, ['%id%' => $emailId]));

            return;
        }

        $event->setChannel('email', $emailId);
        $eventId = (int) $event->getEvent()->getId();

        // The same error three times in a row is the mailer or the network (an SMTP that is down or silent, a blocked sender), not
        // a contact: trying the rest only makes each of them wait for the same failure, and a silent server held the cron for
        // minutes per contact. The rest of the batch fails at once with that reason and core tries it again later.
        $streak     = 0;
        $lastReason = null;
        $stopped    = null;

        /** @var Lead $contact */
        foreach ($event->getContacts() as $logId => $contact) {
            /** @var LeadEventLog $log */
            $log = $event->getPending()->get($logId);

            // Resolved first: a contact left out below still gets its block on the log, so the screens read it as a dispatch of
            // Mautic (not of n8n) and a resend has the values to send.
            $variables = $this->variableResolver->resolveAll($variablesConfig, $contact, $campaign, '<br>', $language);

            if ($this->dncGuard->passIfNotContactable($event, $log, $contact)) {
                $this->record($log, $contact, $emailId, $status, $language, $variables, 'failed', null);

                continue;
            }

            if (null !== $stopped) {
                $this->record($log, $contact, $emailId, $status, $language, $variables, 'failed', null);
                $event->fail($log, $stopped);

                continue;
            }

            $result = $this->sender->send($contact, $email, $eventId, $variables);

            $this->record($log, $contact, $emailId, $status, $language, $variables, $result['outcome'], $result['statId']);

            match ($result['outcome']) {
                DirectEmailSender::FAILED  => $event->fail($log, (string) $result['reason']),
                DirectEmailSender::SKIPPED => $event->passWithError($log, (string) $result['reason']),
                default                    => $event->pass($log),
            };

            if (DirectEmailSender::FAILED === $result['outcome']) {
                $streak     = $result['reason'] === $lastReason ? $streak + 1 : 1;
                $lastReason = $result['reason'];

                if ($streak >= self::STOP_AFTER) {
                    $stopped = $this->failureReasons->translated(DispatchFailureReasons::SEND_STOPPED, ['%count%' => $streak, '%reason%' => (string) $lastReason]);
                }
            } elseif (DirectEmailSender::SKIPPED !== $result['outcome']) {
                $streak     = 0;
                $lastReason = null;
            }
        }
    }

    /**
     * What went out (or was not sent) through Mautic's mailer, on the log. A step core retries after a failure comes back here with the
     * Stats of its earlier attempts on the log, which are kept.
     *
     * @param array<string, mixed> $variables
     */
    private function record(LeadEventLog $log, Lead $contact, int $emailId, string $status, string $language, array $variables, string $outcome, ?int $statId): void
    {
        $log->appendToMetadata(['n8ndispatch' => [
            'mode'               => EmailSendMode::MAUTIC,
            'status'             => $status,
            'mautic_template_id' => $emailId,
            'contact_id'         => $contact->getId(),
            'contact_email'      => $contact->getEmail(),
            'language'           => $language,
            'variables'          => $variables,
            'outcome'            => $outcome,
        ] + MauticEmailOutcome::statFields($log->getMetadata(), $statId)]);
    }
}
