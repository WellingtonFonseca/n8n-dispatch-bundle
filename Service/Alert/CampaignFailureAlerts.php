<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service\Alert;

use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\Entity\EventRepository;
use Mautic\CampaignBundle\Entity\LeadEventLog;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The two alerts of a failed campaign step: `campaign.unpublished` (core just switched the campaign off) and
 * `campaign.failure_warning` (the failures passed the warning percent, still below core's limit). The numbers are
 * core's own: failures of the step divided by the contacts of the campaign (CampaignBundle CampaignEventSubscriber).
 */
class CampaignFailureAlerts
{
    /** Core unpublishes a campaign at this share of failed contacts (a private constant of core, not readable). */
    public const CORE_LIMIT_PERCENT = 10;

    private const WARNING_COOLDOWN_SECONDS = 86400;

    public function __construct(
        private AlertSender $sender,
        private AlertSettings $settings,
        private EventRepository $events,
        private UrlGeneratorInterface $router,
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
                $this->sender->send('campaign.unpublished', $this->payload($log, $step, $campaign));
            }

            return;
        }

        $data = $this->payload($log, $step, $campaign);

        if ($data['contact_count'] >= $settings['warningMinContacts'] && $data['failed_percent'] >= $settings['warningPercent']) {
            $data['warning_percent'] = $settings['warningPercent'];
            $this->sender->send('campaign.failure_warning', $data, 'warning:'.$campaign->getId(), self::WARNING_COOLDOWN_SECONDS);
        }
    }

    /**
     * @return array{campaign: array<string, mixed>, step: array<string, mixed>, failed_count: int, contact_count: int, failed_percent: float, core_limit_percent: int, last_reason: string|null}
     */
    private function payload(LeadEventLog $log, Event $step, Campaign $campaign): array
    {
        $failed   = $this->events->getFailedCount($step);
        $contacts = $campaign->getLeads()->count();

        return [
            'campaign'           => [
                'id'   => $campaign->getId(),
                'name' => $campaign->getName(),
                'url'  => $this->router->generate('mautic_campaign_action', ['objectAction' => 'view', 'objectId' => $campaign->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
            ],
            'step'               => ['id' => $step->getId(), 'name' => $step->getName(), 'type' => $step->getType()],
            'failed_count'       => $failed,
            'contact_count'      => $contacts,
            'failed_percent'     => $contacts > 0 ? round($failed / $contacts * 100, 1) : 100.0,
            'core_limit_percent' => self::CORE_LIMIT_PERCENT,
            'last_reason'        => $log->getFailedLog()?->getReason(),
        ];
    }
}
