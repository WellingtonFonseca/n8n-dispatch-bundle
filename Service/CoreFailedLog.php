<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CampaignBundle\Entity\LeadEventLog;

/**
 * Core marks a log whose event failed with a row in campaign_lead_event_failed_log; the Timeline shows its reason in red
 * ("Último erro") and the alert icon next to the name. An accepted resend means the dispatch worked, so the row goes (the old
 * failure stays in the History table).
 */
final class CoreFailedLog
{
    public static function clear(EntityManagerInterface $em, LeadEventLog $log): void
    {
        $failed = $log->getFailedLog();

        if (null === $failed) {
            return;
        }

        $log->setFailedLog(null);
        $em->remove($failed);
    }
}
