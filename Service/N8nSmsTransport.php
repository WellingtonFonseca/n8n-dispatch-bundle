<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

use Mautic\LeadBundle\Entity\Lead;
use Mautic\SmsBundle\Sms\TransportInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Registers this plugin as an SMS transport of core, only so that core turns its `sms` channel on while the plugin is installed and active:
 * core offers the channels of its preference list (the Do Not Contact options of the contact page, the frequency rules) only when some SMS
 * transport is enabled (SmsBundle ChannelSubscriber), and an SMS transport is enabled when its integration is published. Without one, the
 * contact page can block the email of a contact but not its SMS (and HSM, which follows the `sms` channel).
 *
 * It sends nothing: SMS and HSM go out through the n8n campaign actions (SmsCampaignTriggerSubscriber, HsmCampaignTriggerSubscriber). Core's own
 * "Send text message" action, if somebody uses it, fails with a message that says so.
 */
class N8nSmsTransport implements TransportInterface
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    /**
     * @param mixed $content
     */
    public function sendSms(Lead $lead, $content): string
    {
        return $this->translator->trans('mautic.n8ndispatch.sms_transport.not_supported');
    }
}
