<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Helper\MailHelper;
use Mautic\EmailBundle\Model\EmailModel;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\N8nDispatchBundle\UnsubscribeVariable;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The Email of a campaign step sent straight through Mautic's own mailer (EmailSendMode 'mautic'), one contact at
 * a time. The plugin still resolves the values of the {{variables}} (VariableResolver); here they only travel as the
 * tokens of the mailer, which swaps them in the subject and the HTML. Mautic does the rest: Do Not Contact and bounce,
 * the category the contact unsubscribed from, the frequency rules, the Stat, the tracking of opens and clicks and the
 * one-click unsubscribe. The Email goes out as 'marketing', so those rules apply (a transactional one skips them).
 *
 * It follows what core's own "Send email" campaign action does (EmailBundle CampaignSubscriber): same options, same
 * answer shape, and a contact with no address or who left the category is skipped, not failed, since retrying never helps.
 * One call per contact because the tokens of a call are the same for everyone in it.
 */
class DirectEmailSender
{
    public const SENT    = 'sent';
    public const QUEUED  = 'queued';
    public const SKIPPED = 'skipped';
    public const FAILED  = 'failed';

    public function __construct(
        private EmailSendMode $mode,
        private EmailModel $emailModel,
        private LeadModel $leadModel,
        private DispatchFailureReasons $failureReasons,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
    ) {
    }

    public function isOn(): bool
    {
        return $this->mode->isDirect();
    }

    /**
     * 'sent': the mailer made a Stat (statId); 'queued': it made none, so Mautic held the Email back (a frequency
     * rule or its queue) and sends it later; 'skipped': not sent and not worth a retry; 'failed': the mailer refused.
     *
     * @param array<string, mixed> $variables the resolved {{variables}}, by name
     *
     * @return array{outcome: string, reason: ?string, statId: ?int}
     */
    public function send(Lead $contact, Email $email, int $eventId, array $variables): array
    {
        $credentials = $this->credentials($contact);

        if ('' === $credentials['email']) {
            return $this->result(self::SKIPPED, $this->failureReasons->translated(DispatchFailureReasons::NO_EMAIL_ADDRESS));
        }

        if ($this->leftTheCategory($contact, $email)) {
            return $this->result(self::SKIPPED, $this->failureReasons->translated(DispatchFailureReasons::CATEGORY_UNSUBSCRIBED));
        }

        $contactId = (int) $contact->getId();
        // The newest Stat this step already has for the contact (a contact that comes back to the campaign has older ones):
        // the one this send makes is the first with a higher id. Ids, not dates: a Stat read back from the database has its
        // time shifted by the time zone, one still in memory does not.
        $lastStatId = $this->lastStatId($contactId, $eventId);

        try {
            $errors = $this->emailModel->sendEmail($email, [$contactId => $credentials], [
                'source'         => ['campaign.event', $eventId],
                'email_attempts' => 3,
                'email_priority' => 2,
                'email_type'     => MailHelper::EMAIL_TYPE_MARKETING,
                'return_errors'  => true,
                'dnc_as_error'   => true,
                'ignoreDNC'      => false,
                'customHeaders'  => ['X-EMAIL-ID' => (int) $email->getId()],
                'tokens'         => $this->tokens($variables),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('N8nDispatch: direct send failed for contact '.$contactId.': '.$e->getMessage());

            return $this->result(self::FAILED, $this->failureReasons->reason($e->getMessage()));
        }

        if (is_array($errors) && isset($errors[$contactId])) {
            $reason = $this->failureReasons->reason((string) $errors[$contactId]);

            // Do Not Contact is an answer, not an error: retrying cannot change it (core passes it with a note too).
            return $this->translator->trans('mautic.email.dnc') === $errors[$contactId]
                ? $this->result(self::SKIPPED, $reason)
                : $this->result(self::FAILED, $reason);
        }

        $statId = $this->lastStatId($contactId, $eventId);

        return $statId > $lastStatId ? $this->result(self::SENT, null, $statId) : $this->result(self::QUEUED, null);
    }

    /**
     * The id of the newest Stat of the contact for this campaign step; 0 when there is none.
     */
    private function lastStatId(int $contactId, int $eventId): int
    {
        $stat = $this->emailModel->getStatRepository()->findOneBy(
            ['lead' => $contactId, 'source' => 'campaign.event', 'sourceId' => $eventId],
            ['id' => 'DESC']
        );

        return null === $stat ? 0 : (int) $stat->getId();
    }

    /**
     * What the mailer reads of a contact: its fields (a contact that came without them gets them loaded), the id
     * and, for the owner-is-mailer feature, the owner.
     *
     * @return array<string, mixed>
     */
    private function credentials(Lead $contact): array
    {
        if ([] === $contact->getProfileFields()) {
            $contact->setFields($this->leadModel->getRepository()->getFieldValues($contact->getId()));
        }

        $credentials                      = $contact->getProfileFields();
        $credentials['id']                = (int) $contact->getId();
        $credentials['email']             = (string) ($credentials['email'] ?? $contact->getEmail() ?? '');
        $credentials['firstname']         = (string) ($credentials['firstname'] ?? '');
        $credentials['lastname']          = (string) ($credentials['lastname'] ?? '');
        $credentials['primaryIdentifier'] = $contact->getPrimaryIdentifier();

        if (null !== $contact->getOwner()) {
            $credentials['owner_id'] = $contact->getOwner()->getId();
        }

        return $credentials;
    }

    private function leftTheCategory(Lead $contact, Email $email): bool
    {
        $category = $email->getCategory();

        return null !== $category && in_array($category->getId(), $this->leadModel->getUnsubscribedLeadCategoriesIds($contact), true);
    }

    /**
     * {{name}} => value for every variable, then the unsubscribe placeholder pointing at core's own token. The order
     * matters: the mailer replaces the tokens one after the other, ours first, so the {unsubscribe_url} it lands on is
     * then filled by core with the real link.
     *
     * @param array<string, mixed> $variables
     *
     * @return array<string, string>
     */
    private function tokens(array $variables): array
    {
        $tokens = [];

        foreach ($variables as $name => $value) {
            $tokens['{{'.$name.'}}'] = is_scalar($value) ? (string) $value : '';
        }

        $tokens[UnsubscribeVariable::TOKEN] = '{unsubscribe_url}';

        return $tokens;
    }

    /**
     * @return array{outcome: string, reason: ?string, statId: ?int}
     */
    private function result(string $outcome, ?string $reason, ?int $statId = null): array
    {
        return ['outcome' => $outcome, 'reason' => $reason, 'statId' => $statId];
    }
}
