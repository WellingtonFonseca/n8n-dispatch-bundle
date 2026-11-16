<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service\Alert;

use MauticPlugin\N8nDispatchBundle\Entity\StepFailureRepository;

/**
 * The sweep of the step failure table. It first deletes the rows of contacts that no longer wait on their step, then
 * raises one `campaign.step_failing` alert for each step of a published campaign that holds contacts at or over the
 * threshold. The alert is a state, not an event: it repeats on every run while a contact is stuck, and stops by itself
 * when the count falls to zero (the client fixed the data, cancelled the event or removed the contact).
 */
class FailingStepAlerts
{
    private const TOP_REASONS = 3;

    public function __construct(
        private StepFailureRepository $failures,
        private AlertSender $sender,
        private AlertSettings $settings,
        private CampaignLink $link,
    ) {
    }

    /**
     * @return array{cleaned: int, steps: int, contacts: int}
     */
    public function sweep(\DateTimeImmutable $now): array
    {
        $cleaned   = $this->failures->deleteResolved();
        $threshold = $this->settings->current()['failingThreshold'];
        $steps     = $this->failures->failingSteps($threshold);
        $reasons   = $this->reasonsByStep($this->failures->reasons($threshold));
        $contacts  = 0;

        foreach ($steps as $step) {
            $contacts += $step['contacts'];
            // runs with the alerts off too: the sender logs the alert as "not sent", so someone can see what they miss
            $this->sender->send('campaign.step_failing', $this->payload($step, $reasons[$step['event_id']] ?? [], $threshold, $now));
        }

        return ['cleaned' => $cleaned, 'steps' => count($steps), 'contacts' => $contacts];
    }

    /**
     * @param list<array{event_id: int, reason: string, contacts: int}> $rows
     *
     * @return array<int, list<array{reason: string, contacts: int}>> the most common reasons of each step
     */
    private function reasonsByStep(array $rows): array
    {
        $byStep = [];

        foreach ($rows as $row) {
            if (count($byStep[$row['event_id']] ?? []) < self::TOP_REASONS) {
                $byStep[$row['event_id']][] = ['reason' => $row['reason'], 'contacts' => $row['contacts']];
            }
        }

        return $byStep;
    }

    /**
     * @param array{campaign_id: int, campaign_name: string, event_id: int, step_name: string, step_type: string, contacts: int, max_failures: int, oldest: string} $step
     * @param list<array{reason: string, contacts: int}>                                                                                                           $topReasons
     *
     * @return array<string, mixed>
     */
    private function payload(array $step, array $topReasons, int $threshold, \DateTimeImmutable $now): array
    {
        $oldest = new \DateTimeImmutable($step['oldest'], new \DateTimeZone('UTC'));

        return [
            'campaign'             => $this->link->named($step['campaign_id'], $step['campaign_name']),
            'step'                 => ['id' => $step['event_id'], 'name' => $step['step_name'], 'type' => $step['step_type']],
            'failing_contacts'     => $step['contacts'],
            'threshold'            => $threshold,
            'max_failures'         => $step['max_failures'],
            'oldest_failure_at'    => $oldest->format(\DATE_ATOM),
            'oldest_failure_hours' => max(0, intdiv($now->getTimestamp() - $oldest->getTimestamp(), 3600)),
            'top_reasons'          => $topReasons,
        ];
    }
}
