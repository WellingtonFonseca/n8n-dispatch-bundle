<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CampaignBundle\Entity\LeadEventLog;
use Mautic\EmailBundle\Entity\Email;
use Mautic\LeadBundle\Entity\DoNotContact;
use Mautic\LeadBundle\Model\DoNotContact as DncModel;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\N8nDispatchBundle\Entity\DispatchTracking;
use MauticPlugin\N8nDispatchBundle\Twig\StatusExtension;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Reenviar" on the Dispatches screen: sends to n8n again the very same body
 * the dispatch went out with (the 'Body' of its card, kept on the campaign
 * log), to the same webhook with the same headers — a "recall", so what
 * comes from the contact or from Custom Objects is not resolved again.
 *
 * No new campaign log row is made (the log is unique per contact, step and
 * rotation): the same row is updated, whatever n8n answers. The attempt is
 * recorded as the first dispatch would have been: on success its response,
 * tracking ids and callback history replace the old ones, with a note saying
 * who resent it and when; on failure the failed response and the reason are
 * what the card shows (and the old tracking, which belonged to the replaced
 * dispatch, goes). Either way the base data (event, contact, rotation) and,
 * for an Email, the Stat stay as they were.
 *
 * Only a step still in production is resent (setting it to test or paused
 * stops resends too), and only a dispatch that ended badly: a callback in
 * error (HSM: Meta's, not the Mirror's) or a call n8n refused. The Do Not
 * Contact list is still honoured: a contact who opted out since is not sent
 * to, and nothing is recorded since no call was made.
 */
class DispatchResender
{
    /** What the log adds to the body it sent; not part of what goes out again. */
    private const RESPONSE_KEYS = ['response', 'httpStatusCode', 'templateCopyHash'];

    private const ACTION_BY_CHANNEL = [
        DispatchTracking::CHANNEL_EMAIL => 'email.send',
        DispatchTracking::CHANNEL_SMS   => 'sms.send',
        DispatchTracking::CHANNEL_HSM   => 'hsm.send',
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private IntegrationHelper $integrationHelper,
        private HttpClientInterface $httpClient,
        private DncModel $dncModel,
        private StatusTracker $tracker,
        private StatusExtension $dates,
        private DispatchFailureReasons $failureReasons,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
        private ?DirectEmailSender $directSender = null,
    ) {
    }

    /**
     * Whether, of the callbacks the Dispatches screen shows for a log (HSM:
     * Meta's, not the Mirror's), any ended in error.
     *
     * @param list<array{refType: string, outcome: string}> $callbacks as StatusTracker::viewForMetadata() returns them
     */
    public static function hasErrorCallback(array $callbacks): bool
    {
        foreach ($callbacks as $callback) {
            if (DispatchTracking::REF_HSM_ID !== $callback['refType'] && DispatchTracking::OUTCOME_ERROR === $callback['outcome']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the call itself ended badly: n8n refused it (HTTP outside 2xx)
     * or the log carries a failure mark.
     *
     * @param array<string, mixed> $metadata
     */
    public static function isFailed(array $metadata): bool
    {
        $status = $metadata['n8ndispatch']['httpStatusCode'] ?? null;

        return !empty($metadata['failed']) || (is_numeric($status) && ((int) $status < 200 || (int) $status >= 300));
    }

    /**
     * The body the dispatch went out with: the log's 'n8ndispatch' block
     * without what was added after n8n answered. Null when there is none.
     *
     * @param array<string, mixed> $metadata
     *
     * @return array<string, mixed>|null
     */
    public static function bodyOf(array $metadata): ?array
    {
        $n8n = $metadata['n8ndispatch'] ?? null;

        if (!is_array($n8n) || 'production' !== ($n8n['status'] ?? null)) {
            return null;
        }

        return array_diff_key($n8n, array_flip(self::RESPONSE_KEYS));
    }

    /**
     * The log's metadata after a resend n8n accepted: the new response and
     * its ids in place of the old ones, the failure marks cleared, every
     * other key kept.
     *
     * @param array<string, mixed> $old
     * @param array<string, mixed> $body the body that was sent again
     *
     * @return array<string, mixed>
     */
    public static function metadataAfter(string $channel, array $old, array $body, int $statusCode, string $rawBody): array
    {
        $decoded = json_decode($rawBody, true);

        $new = array_diff_key($old, array_flip(['errors', 'failed', 'reason', 'n8ndispatch_resend', 'logSendEmailId', 'logSendSmsId', 'logSendHsmId', 'logSendHsmUuid']));

        $n8n = $body + [
            'response'       => is_array($decoded) ? $decoded : $rawBody,
            'httpStatusCode' => $statusCode,
        ];

        if (isset($old['n8ndispatch']['templateCopyHash'])) {
            $n8n['templateCopyHash'] = $old['n8ndispatch']['templateCopyHash'];
        }

        $new['n8ndispatch'] = $n8n;

        return $new + (new DispatchLogReader())->refs($channel, $new);
    }

    /**
     * The log's metadata after a resend that n8n refused (or never answered):
     * the failed response and the reason in place of the old ones, no ids
     * (nothing was accepted), the note kept so the card says who tried and when.
     *
     * @param array<string, mixed> $old
     * @param array<string, mixed> $body the body that was sent again
     *
     * @return array<string, mixed>
     */
    public static function metadataAfterFailure(array $old, array $body, int $statusCode, string $rawBody, string $reason, string $note): array
    {
        $decoded = json_decode($rawBody, true);

        $new = array_diff_key($old, array_flip(['errors', 'logSendEmailId', 'logSendSmsId', 'logSendHsmId', 'logSendHsmUuid']));

        $new['n8ndispatch'] = $body + [
            'response'       => is_array($decoded) ? $decoded : $rawBody,
            'httpStatusCode' => $statusCode,
        ];

        if (isset($old['n8ndispatch']['templateCopyHash'])) {
            $new['n8ndispatch']['templateCopyHash'] = $old['n8ndispatch']['templateCopyHash'];
        }

        $new['failed']             = 1;
        $new['reason']             = $reason;
        $new['n8ndispatch_resend'] = $note;

        return $new;
    }

    /**
     * @return array{ok: bool, message: string} message already translated, ready for the screen
     */
    public function resend(int $logId, ?string $userEmail): array
    {
        /** @var LeadEventLog|null $log */
        $log = $this->em->find(LeadEventLog::class, $logId);

        $channel = null === $log ? null : DispatchLogReader::channelOf((string) $log->getEvent()->getType());

        if (null === $log || null === $channel) {
            return $this->result(false, 'mautic.n8ndispatch.dispatch.resend.not_found');
        }

        $metadata = $log->getMetadata();

        // Not a webhook call: the Email goes out again through Mautic's mailer, as a new Stat.
        if (MauticEmailOutcome::isDirect($metadata)) {
            return $this->resendDirect($log, $metadata, $userEmail);
        }

        if ('production' !== ($log->getEvent()->getProperties()['status'] ?? 'test')) {
            return $this->result(false, 'mautic.n8ndispatch.dispatch.resend.not_production');
        }

        if (!self::hasErrorCallback($this->tracker->viewForMetadata($metadata)) && !self::isFailed($metadata)) {
            return $this->result(false, 'mautic.n8ndispatch.dispatch.resend.not_error');
        }

        $body = self::bodyOf($metadata);

        if (null === $body) {
            return $this->result(false, 'mautic.n8ndispatch.dispatch.resend.no_body');
        }

        $dncChannel = DispatchTracking::CHANNEL_EMAIL === $channel ? 'email' : 'sms';

        if (DoNotContact::IS_CONTACTABLE !== $this->dncModel->isContactable($log->getLead(), $dncChannel)) {
            return $this->failed($this->failureReasons->translated(DispatchFailureReasons::DO_NOT_CONTACT, ['%channel%' => $dncChannel]));
        }

        $webhook = WebhookSettings::forAction($this->integrationHelper, self::ACTION_BY_CHANNEL[$channel]);

        if (is_string($webhook)) {
            return $this->failed($this->failureReasons->translated($webhook));
        }

        ['url' => $webhookUrl, 'headers' => $headers] = $webhook;

        $note = $this->note($userEmail);

        try {
            $response   = $this->httpClient->request('POST', $webhookUrl, ['headers' => $headers, 'json' => $body]);
            $statusCode = $response->getStatusCode();
            $rawBody    = $response->getContent(false);
        } catch (\Throwable $e) {
            $this->logger->error('N8nDispatch: resend failed for log '.$logId.': '.$e->getMessage());

            // The call was made but never answered: recorded as a failed attempt, status 0 (not a 2xx).
            return $this->recordFailure($log, $channel, $body, 0, $e->getMessage(), $this->failureReasons->reason($e->getMessage()), $note, $userEmail);
        }

        // HSM's workflow does not always return a body; an empty one is a failure there too (same rule as its listener).
        if ($statusCode >= 300 || (DispatchTracking::CHANNEL_HSM === $channel && '' === trim($rawBody))) {
            $reason = $this->failureReasons->reason(
                '' === trim($rawBody) ? $this->failureReasons->text(DispatchFailureReasons::EMPTY_RESPONSE, ['%action%' => self::ACTION_BY_CHANNEL[$channel]]) : $this->failureReasons->fromResponseBody($rawBody, $statusCode)
            );

            return $this->recordFailure($log, $channel, $body, $statusCode, $rawBody, $reason, $note, $userEmail);
        }

        $new = self::metadataAfter($channel, $metadata, $body, $statusCode, $rawBody);

        $log->setMetadata($new);
        $log->setDateTriggered(new \DateTime());
        $log->setIsScheduled(false);
        $this->clearFailedLog($log);
        $this->em->persist($log);
        $this->em->flush();

        // Old tracking out (even if n8n answered the same id; its history stays), the attempt and the
        // new tracking in, with the note on its first entry.
        $refs = array_intersect_key($new, array_flip(DispatchTracking::REFS_BY_CHANNEL[$channel]));
        $this->tracker->forget($metadata, $logId);
        $this->tracker->recordDispatch($logId, true, $refs, self::responseOf($rawBody), null, self::resendInfo($userEmail, new \DateTimeImmutable('now', new \DateTimeZone('UTC'))));
        $this->tracker->register($channel, $refs, null, $logId);
        $this->tracker->annotate($new, $this->callbackMessage($userEmail));

        return $this->result(true, 'mautic.n8ndispatch.dispatch.resend.done');
    }

    /**
     * Core marks a log whose event failed with a row in campaign_lead_event_failed_log; the Timeline shows its reason
     * in red ("Último erro") and the alert icon next to the name. An accepted resend means the dispatch worked, so the
     * row goes (the old failure stays in the History table).
     */
    private function clearFailedLog(LeadEventLog $log): void
    {
        $failed = $log->getFailedLog();
        if (null === $failed) {
            return;
        }

        $log->setFailedLog(null);
        $this->em->remove($failed);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{ok: bool, message: string}
     */
    private function recordFailure(LeadEventLog $log, string $channel, array $body, int $statusCode, string $rawBody, string $reason, string $note, ?string $userEmail): array
    {
        $old = $log->getMetadata();

        $log->setMetadata(self::metadataAfterFailure($old, $body, $statusCode, $rawBody, $reason, $note));
        $log->setDateTriggered(new \DateTime());
        $log->setIsScheduled(false);
        $this->em->persist($log);
        $this->em->flush();

        // The tracking belonged to the dispatch this attempt replaced (its history stays); the
        // refusal is an entry of its own, with n8n's message.
        $this->tracker->forget($old, (int) $log->getId());
        $this->tracker->recordDispatch((int) $log->getId(), false, [], self::responseOf($rawBody), null, self::resendInfo($userEmail, new \DateTimeImmutable('now', new \DateTimeZone('UTC'))));

        return $this->failed($reason);
    }

    /**
     * "Reenviar" of an Email that went out through Mautic's own mailer: only a send the mailer refused, with the step still in
     * production. It goes out again with the values of the first send (nothing is resolved again, like the n8n resend), as
     * ONE MORE Stat, so the number of Stats of the dispatch is its number of attempts. The same log row is updated (a log is
     * unique per step, contact and rotation): the new Stat joins the old ones, the failure marks go and a note says who
     * resent it and when. A resend that fails again is recorded the same way and stays resendable.
     *
     * @param array<string, mixed> $metadata
     *
     * @return array{ok: bool, message: string}
     */
    private function resendDirect(LeadEventLog $log, array $metadata, ?string $userEmail): array
    {
        $properties = $log->getEvent()->getProperties();

        if ('production' !== ($properties['status'] ?? 'test')) {
            return $this->result(false, 'mautic.n8ndispatch.dispatch.resend.not_production');
        }

        if (null === $this->directSender) {
            return $this->result(false, 'mautic.n8ndispatch.dispatch.resend.direct_not_available');
        }

        if (self::isFailed($metadata) && 'failed' === MauticEmailOutcome::outcomeOf($metadata)) {
            $email = $this->em->find(Email::class, (int) ($properties['email'] ?? 0));

            if (null === $email) {
                return $this->failed($this->failureReasons->translated(DispatchFailureReasons::EMAIL_TEMPLATE_DELETED, ['%id%' => (int) ($properties['email'] ?? 0)]));
            }

            if (!$email->isPublished()) {
                return $this->failed($this->failureReasons->translated(DispatchFailureReasons::EMAIL_NOT_PUBLISHED, ['%id%' => (int) $email->getId()]));
            }

            if (DoNotContact::IS_CONTACTABLE !== $this->dncModel->isContactable($log->getLead(), 'email')) {
                return $this->failed($this->failureReasons->translated(DispatchFailureReasons::DO_NOT_CONTACT, ['%channel%' => 'email']));
            }

            return $this->sendAgain($log, $metadata, $email, $userEmail);
        }

        return $this->result(false, 'mautic.n8ndispatch.dispatch.resend.direct_not_refused');
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @return array{ok: bool, message: string}
     */
    private function sendAgain(LeadEventLog $log, array $metadata, Email $email, ?string $userEmail): array
    {
        $variables = is_array($metadata['n8ndispatch']['variables'] ?? null) ? $metadata['n8ndispatch']['variables'] : [];
        $result    = $this->directSender->send($log->getLead(), $email, (int) $log->getEvent()->getId(), $variables);
        $refused   = in_array($result['outcome'], [DirectEmailSender::FAILED, DirectEmailSender::SKIPPED], true);

        $new = array_diff_key($metadata, array_flip(['errors', 'failed', 'reason', 'n8ndispatch_resend']));

        $new['n8ndispatch']            = array_diff_key($metadata['n8ndispatch'], array_flip(['statId', 'statIds'])) + MauticEmailOutcome::statFields($metadata, $result['statId']);
        $new['n8ndispatch']['outcome'] = $result['outcome'];

        $new['n8ndispatch_resend'] = $this->note($userEmail);

        if ($refused) {
            $new['failed'] = 1;
            $new['reason'] = (string) $result['reason'];
        }

        $log->setMetadata($new);
        $log->setDateTriggered(new \DateTime());
        $log->setIsScheduled(false);

        if (!$refused) {
            $this->clearFailedLog($log);
        }

        $this->em->persist($log);
        $this->em->flush();

        return $refused ? $this->failed((string) $result['reason']) : $this->result(true, 'mautic.n8ndispatch.dispatch.resend.done');
    }

    /**
     * Who resent it and when, as the History keeps it: the user's email (left
     * out when there is none) and the moment in UTC, 'Y-m-d H:i'.
     *
     * @return array<string, string>
     */
    public static function resendInfo(?string $userEmail, \DateTimeImmutable $now): array
    {
        $info = null === $userEmail || '' === $userEmail ? [] : ['por' => $userEmail];

        return $info + ['em' => $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i')];
    }

    /**
     * n8n's answer as the History keeps it: decoded when it is JSON, the text
     * when it is not (a failed call keeps the error text here), nothing when empty.
     */
    public static function responseOf(string $rawBody): mixed
    {
        $trimmed = trim($rawBody);

        if ('' === $trimmed) {
            return null;
        }

        $decoded = json_decode($trimmed, true);

        return is_array($decoded) ? $decoded : $trimmed;
    }

    /**
     * The pending callback's message: {"reenvio": {"por": ..., "em": ...}}, the
     * moment in the system language, same shape as the dispatch entry's.
     *
     * @return array{reenvio: array<string, string>}
     */
    private function callbackMessage(?string $userEmail): array
    {
        $info       = self::resendInfo($userEmail, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $info['em'] = $this->dates->date(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

        return ['reenvio' => $info];
    }

    private function note(?string $userEmail): string
    {
        $lines = [$this->translator->trans('mautic.n8ndispatch.dispatch.resend.note.date', [
            '%date%' => $this->dates->date(new \DateTimeImmutable('now', new \DateTimeZone('UTC'))),
        ])];

        if (null !== $userEmail && '' !== $userEmail) {
            $lines[] = $this->translator->trans('mautic.n8ndispatch.dispatch.resend.note.by', ['%email%' => $userEmail]);
        }

        return implode("\n", $lines);
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function failed(string $reason): array
    {
        return ['ok' => false, 'message' => $this->translator->trans('mautic.n8ndispatch.dispatch.resend.failed', ['%reason%' => $reason])];
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
