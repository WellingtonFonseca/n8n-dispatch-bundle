<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\CampaignBundle\Entity\LeadEventLog;
use Mautic\CampaignBundle\EventCollector\EventCollector;
use Mautic\CampaignBundle\Event\PendingEvent;
use MauticPlugin\N8nDispatchBundle\Entity\DispatchTracking;
use MauticPlugin\N8nDispatchBundle\Twig\StatusExtension;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Reenviar" on the Dispatches screen: calls n8n again for a dispatch whose
 * callback ended in error, as if the campaign step ran once more for that
 * contact — the step's own listener (Email/SMS/HSM CampaignTriggerSubscriber)
 * does the work, fed a PendingEvent holding just that log. No new campaign
 * log row is made (the log is unique per contact, step and rotation), the
 * same row is updated: its response, its tracking ids and callback history
 * are replaced by the new dispatch's, with a note saying who resent it and
 * when. Its base data (event, contact, rotation) stays as it was.
 *
 * Only a production step is resent (a test/paused one would not call n8n),
 * and only when the callback in error is the one the Dispatches screen
 * shows (HSM: Meta's). If the new call fails, the log goes back exactly as
 * it was and nothing is replaced.
 */
class DispatchResender
{
    public function __construct(
        private EntityManagerInterface $em,
        private EventDispatcherInterface $dispatcher,
        private EventCollector $eventCollector,
        private StatusTracker $tracker,
        private StatusExtension $dates,
        private TranslatorInterface $translator,
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
     * @return array{ok: bool, message: string} message already translated, ready for the screen
     */
    public function resend(int $logId, ?string $userEmail): array
    {
        /** @var LeadEventLog|null $log */
        $log = $this->em->find(LeadEventLog::class, $logId);

        if (null === $log || null === DispatchLogReader::channelOf((string) $log->getEvent()->getType())) {
            return $this->result(false, 'mautic.n8ndispatch.dispatch.resend.not_found');
        }

        $event    = $log->getEvent();
        $metadata = $log->getMetadata();

        if ('production' !== ($event->getProperties()['status'] ?? 'test')
            || !self::hasErrorCallback($this->tracker->viewForMetadata($metadata))) {
            return $this->result(false, 'mautic.n8ndispatch.dispatch.resend.not_allowed');
        }

        $before = [
            'metadata'      => $metadata,
            'dateTriggered' => $log->getDateTriggered(),
            'isScheduled'   => $log->getIsScheduled(),
            'failedLog'     => $log->getFailedLog(),
        ];

        $config  = $this->eventCollector->getEventConfig($event);
        $pending = new PendingEvent($config, $event, new ArrayCollection([$log->getId() => $log]));
        $this->dispatcher->dispatch($pending, $config->getBatchEventName());

        if ($pending->getFailures()->count() > 0 || 0 === $pending->getSuccessful()->count()) {
            $reason = (string) ($log->getMetadata()['reason'] ?? '');
            $this->restore($log, $before);

            return $this->result(false, 'mautic.n8ndispatch.dispatch.resend.failed', ['%reason%' => $reason]);
        }

        $this->em->persist($log);
        $this->em->flush();

        $this->tracker->forget($this->idsReplaced($metadata, $log->getMetadata()));
        $this->tracker->annotate($log->getMetadata(), $this->note($userEmail));

        return $this->result(true, 'mautic.n8ndispatch.dispatch.resend.done');
    }

    /**
     * @param array<string, mixed> $before
     */
    private function restore(LeadEventLog $log, array $before): void
    {
        $log->setMetadata($before['metadata']);
        $log->setDateTriggered($before['dateTriggered']);
        $log->setIsScheduled($before['isScheduled']);

        // PendingEvent::fail() attaches a failure record; the log was fine before, so none stays.
        if (null === $before['failedLog'] && null !== $log->getFailedLog()) {
            $log->getFailedLog()->setLog(null);
            $log->setFailedLog(null);
        }

        $this->em->persist($log);
        $this->em->flush();
    }

    /**
     * The ids of the dispatch that was replaced: the old metadata's ids that
     * the new metadata no longer holds (the new ones must stay tracked).
     *
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     *
     * @return array<string, mixed>
     */
    private function idsReplaced(array $old, array $new): array
    {
        $ids = [];

        foreach (array_merge(...array_values(DispatchTracking::REFS_BY_CHANNEL)) as $refType) {
            if (isset($old[$refType]) && (string) $old[$refType] !== (string) ($new[$refType] ?? '')) {
                $ids[$refType] = $old[$refType];
            }
        }

        return $ids;
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
     * @param array<string, string> $params
     *
     * @return array{ok: bool, message: string}
     */
    private function result(bool $ok, string $key, array $params = []): array
    {
        return ['ok' => $ok, 'message' => $this->translator->trans($key, $params)];
    }
}
