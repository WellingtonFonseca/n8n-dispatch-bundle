<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service\Alert;

use Mautic\CampaignBundle\Entity\LeadEventLog;

/**
 * The alert `dispatch.resend_failed`: somebody pressed Reenviar on the Dispatches screen and the dispatch failed again.
 */
class ResendFailedAlert
{
    public function __construct(private AlertSender $sender, private CampaignLink $link)
    {
    }

    public function raise(LeadEventLog $log, string $channel, string $reason, ?string $userEmail): void
    {
        $step     = $log->getEvent();
        $campaign = $step->getCampaign();

        $this->sender->send('dispatch.resend_failed', [
            'log_id'      => $log->getId(),
            'channel'     => $channel,
            'reason'      => $reason,
            'resent_by'   => $userEmail,
            'contact_id'  => $log->getLead()->getId(),
            'step'        => ['id' => $step->getId(), 'name' => $step->getName()],
            'campaign'    => $this->link->reference($campaign),
        ]);
    }
}
