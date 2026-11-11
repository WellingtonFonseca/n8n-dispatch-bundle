<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service\Provider;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CampaignBundle\Entity\LeadEventLog;
use Mautic\LeadBundle\Entity\DoNotContact;
use Mautic\LeadBundle\Model\DoNotContact as DncModel;
use MauticPlugin\N8nDispatchBundle\Service\Alert\ResendFailedAlert;
use MauticPlugin\N8nDispatchBundle\Service\CoreFailedLog;
use MauticPlugin\N8nDispatchBundle\Service\DispatchFailureReasons;
use MauticPlugin\N8nDispatchBundle\Service\ResendVariables;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Reenviar" of an HSM handed to another plugin (DispatchResender calls it for such a log, after the step-in-production check).
 *
 * Only a message the other plugin REFUSED is resent here: what fails after it accepted the message (the Meta's refusal,
 * delivery) is shown and resent by that plugin. The request goes out again as the log kept it, or with the values the user
 * confirmed on the resend form. The same log row is updated (a log is unique per step, contact and rotation); a refused resend
 * is recorded the same way and stays resendable.
 */
class ProviderResender
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly HsmProviderGateway $gateway,
        private readonly DncModel $dncModel,
        private readonly DispatchFailureReasons $failureReasons,
        private readonly TranslatorInterface $translator,
        private readonly ?ResendVariables $resendVariables = null,
        private readonly ?ResendFailedAlert $failedAlert = null,
    ) {
    }

    /**
     * @param array<string, string>|null $confirmed the values of the resend form by variable; null resends the first values
     * @param string                     $note      who resent it and when, already translated
     *
     * @return array{ok: bool, message: string} message already translated, ready for the screen
     */
    public function resend(LeadEventLog $log, ?array $confirmed, string $note, ?string $userEmail): array
    {
        $metadata = $log->getMetadata();

        if (HsmProviderOutcome::FAILED !== HsmProviderOutcome::outcomeOf($metadata)) {
            return $this->result(false, 'mautic.n8ndispatch.dispatch.resend.provider_not_refused');
        }

        if (!$this->gateway->isAvailable()) {
            return $this->result(false, 'mautic.n8ndispatch.dispatch.resend.provider_not_available');
        }

        $request = HsmSendRequest::fromArray((array) ($metadata['n8ndispatch']['request'] ?? []), $log->getEvent()->getCampaign()->getId(), $log->getEvent()->getId());

        if (null === $request) {
            return $this->result(false, 'mautic.n8ndispatch.dispatch.resend.no_body');
        }

        if (null !== $confirmed) {
            $request = $request->withVariables($confirmed + $request->variables);

            if (null !== $empty = $this->emptyFormValues($log, $request)) {
                return $empty;
            }
        }

        if (DoNotContact::IS_CONTACTABLE !== $this->dncModel->isContactable($log->getLead(), 'sms')) {
            return $this->failed($this->failureReasons->translated(DispatchFailureReasons::DO_NOT_CONTACT, ['%channel%' => 'sms']));
        }

        return $this->sendAgain($log, $request, $note, $userEmail);
    }

    private function sendAgain(LeadEventLog $log, HsmSendRequest $request, string $note, ?string $userEmail): array
    {
        $empty = $request->firstEmptyVariable();

        // The Meta refuses a parameter with no text: the same rule as the first send, so nothing reaches the other plugin.
        $result = null === $empty
            ? $this->gateway->send($request)
            : HsmSendResult::refused($this->failureReasons->text(DispatchFailureReasons::VARIABLE_EMPTY, ['%number%' => (string) $empty]));

        $log->setMetadata(HsmProviderOutcome::metadataAfterResend($log->getMetadata(), $request, $result, $note));
        // Core's setter also clears the scheduled flag of the log.
        $log->setDateTriggered(new \DateTime());

        if ($result->accepted) {
            CoreFailedLog::clear($this->em, $log);
        }

        $this->em->persist($log);
        $this->em->flush();

        if ($result->accepted) {
            return $this->result(true, 'mautic.n8ndispatch.dispatch.resend.done');
        }

        $reason = $this->failureReasons->reason((string) $result->error);
        $this->failedAlert?->raise($log, 'hsm', $reason, $userEmail);

        return $this->failed($reason);
    }

    /**
     * The refusal of a form with a variable of the template left empty: nothing is sent or recorded. Null when complete.
     *
     * @return array{ok: bool, message: string}|null
     */
    private function emptyFormValues(LeadEventLog $log, HsmSendRequest $request): ?array
    {
        if (null === $this->resendVariables) {
            return null;
        }

        $names = $this->resendVariables->emptyNames($log, $request->variables);

        return [] === $names ? null : $this->result(false, 'mautic.n8ndispatch.dispatch.resend.empty_variables', ['%names%' => implode(', ', $names)]);
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function failed(string $reason): array
    {
        return $this->result(false, 'mautic.n8ndispatch.dispatch.resend.failed', ['%reason%' => $reason]);
    }

    /**
     * @param array<string, string> $params
     *
     * @return array{ok: bool, message: string}
     */
    private function result(bool $ok, string $key, array $params = []): array
    {
        return ['ok' => $ok, 'message' => $this->translator->trans($key, $params)];
    }
}
