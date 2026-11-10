<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service\Alert;

use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\Entity\EventRepository;
use Mautic\CampaignBundle\Entity\LeadEventLog;

/**
 * The two alerts of a failed campaign step: `campaign.unpublished` (core just switched the campaign off) and
 * `campaign.failure_warning` (the failures passed the warning percent, still below core's limit). The numbers are
 * core's own: failures of the step divided by the contacts of the campaign (CampaignBundle CampaignEventSubscriber, which counts a contact only after 100 failures of the step).
 */
class CampaignFailureAlerts
{
    /**
     * Core 7 unpublishes a campaign at this share of failed contacts, and only with 100 contacts or more (private constants of
     * core, not readable). A contact counts as failed for a step after 100 failures of that step (CampaignEventSubscriber::LOOPS_TO_FAIL).
     */
    public const CORE_LIMIT_PERCENT = 35;

    private const WARNING_COOLDOWN_SECONDS = 86400;

    public function __construct(
        private AlertSender $sender,
        private AlertSettings $settings,
        private EventRepository $events,
        private CampaignLink $link,
        private AlertThrottle $throttle,
    ) {
    }

    /**
     * @param bool $wasPublished the campaign state before core handled this failure
     */
    public function afterFailure(LeadEventLog $log, bool $wasPublished): void
    {
        $settings = $this->settings->current();

        if (!$settings['enabled']) {
            return;
        }

        $step     = $log->getEvent();
        $campaign = $step->getCampaign();

        if (!$campaign->isPublished()) {
            if ($wasPublished) {
                $this->sender->send('campaign.unpublished', $this->payload($log, $step, $campaign, $this->numbers($step, $campaign)));
            }

            return;
        }

        // a failing step of a big campaign passes here once per contact: no queries while the warning is already out
        if ($this->throttle->isTaken('warning:'.$campaign->getId())) {
            return;
        }

        $numbers = $this->numbers($step, $campaign);

        if ($numbers['contacts'] < $settings['warningMinContacts'] || $numbers['percent'] < $settings['warningPercent']) {
            return;
        }

        $data                    = $this->payload($log, $step, $campaign, $numbers);
        $data['warning_percent'] = $settings['warningPercent'];
        $this->sender->send('campaign.failure_warning', $data, 'warning:'.$campaign->getId(), self::WARNING_COOLDOWN_SECONDS);
    }

    /**
     * @return array{failed: int, contacts: int, percent: float}
     */
    private function numbers(Event $step, Campaign $campaign): array
    {
        $failed   = $this->events->getFailedCount($step);
        $contacts = $campaign->getLeads()->count();

        return ['failed' => $failed, 'contacts' => $contacts, 'percent' => $contacts > 0 ? round($failed / $contacts * 100, 1) : 100.0];
    }

    /**
     * @param array{failed: int, contacts: int, percent: float} $numbers
     *
     * @return array{campaign: array<string, mixed>, step: array<string, mixed>, failed_count: int, contact_count: int, failed_percent: float, core_limit_percent: int, last_reason: string|null}
     */
    private function payload(LeadEventLog $log, Event $step, Campaign $campaign, array $numbers): array
    {
        return [
            'campaign'           => $this->link->reference($campaign),
            'step'               => ['id' => $step->getId(), 'name' => $step->getName(), 'type' => $step->getType()],
            'failed_count'       => $numbers['failed'],
            'contact_count'      => $numbers['contacts'],
            'failed_percent'     => $numbers['percent'],
            'core_limit_percent' => self::CORE_LIMIT_PERCENT,
            'last_reason'        => $log->getFailedLog()?->getReason(),
        ];
    }
}
