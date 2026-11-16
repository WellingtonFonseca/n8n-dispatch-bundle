<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

use Mautic\CampaignBundle\Entity\LeadEventLog;
use Mautic\CampaignBundle\Event\PendingEvent;
use Mautic\LeadBundle\Entity\DoNotContact;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\DoNotContact as DncModel;

/**
 * The Do Not Contact check of the Email step, shared by its two ways out (the n8n webhook and Mautic's mailer).
 */
class EmailDncGuard
{
    public function __construct(private DncModel $dncModel, private DispatchFailureReasons $failureReasons)
    {
    }

    /**
     * Leaves the contact out, with no call at all, when it is on the Do Not Contact list for the email channel.
     *
     * The step passes with the reason on the History (core's own Email action does the same): a DNC is an answer,
     * and a failed step would be tried again by core every hour with no end.
     *
     * Before anything is resolved or sent, for both ways out. The n8n path never goes through Mautic's own mailer,
     * so core's native DNC enforcement (EmailModel::sendEmail() checking this same table before it queues a
     * message) never runs for it, including for a contact who used the unsubscribe link the Email step itself
     * generates, which lands here as a plain 'email'-channel DNC row via core's PublicController::unsubscribeAction().
     * Checked first, an opted-out contact is never sent to n8n at all. The direct path asks again inside the mailer
     * (and gets the bounce too), this one keeps the same reason on the History for both.
     *
     * @return bool true when the contact was left out
     */
    public function passIfNotContactable(PendingEvent $event, LeadEventLog $log, Lead $contact): bool
    {
        if (DoNotContact::IS_CONTACTABLE === $this->dncModel->isContactable($contact, 'email')) {
            return false;
        }

        $event->passWithError($log, $this->failureReasons->translated(DispatchFailureReasons::DO_NOT_CONTACT, ['%channel%' => 'email']));

        return true;
    }
}
