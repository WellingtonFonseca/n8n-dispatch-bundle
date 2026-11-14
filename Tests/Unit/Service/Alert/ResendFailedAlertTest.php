<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service\Alert;

use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\Entity\LeadEventLog;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\N8nDispatchBundle\Service\Alert\AlertSender;
use MauticPlugin\N8nDispatchBundle\Service\Alert\CampaignLink;
use MauticPlugin\N8nDispatchBundle\Service\Alert\ResendFailedAlert;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[Group('area:alerts')]
#[Group('risk:CRITICO')]
class ResendFailedAlertTest extends TestCase
{
    public function testTheAlertNamesTheLogTheContactTheStepTheCampaignTheReasonAndWhoResent(): void
    {
        $campaign = $this->createStub(Campaign::class);
        $campaign->method('getId')->willReturn(7);
        $campaign->method('getName')->willReturn('Boas-vindas');
        $step = $this->createStub(Event::class);
        $step->method('getId')->willReturn(31);
        $step->method('getName')->willReturn('SMS de lembrete');
        $step->method('getCampaign')->willReturn($campaign);
        $lead = $this->createStub(Lead::class);
        $lead->method('getId')->willReturn(55);
        $log = $this->createStub(LeadEventLog::class);
        $log->method('getId')->willReturn(140);
        $log->method('getEvent')->willReturn($step);
        $log->method('getLead')->willReturn($lead);
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->expects($this->once())->method('generate')
            ->with('mautic_campaign_action', ['objectAction' => 'view', 'objectId' => 7], UrlGeneratorInterface::ABSOLUTE_URL)
            ->willReturn('https://mautic.test/campaign/7');
        $sender = $this->createMock(AlertSender::class);
        $sender->expects($this->once())->method('send')->with('dispatch.resend_failed', [
            'log_id'     => 140,
            'channel'    => 'sms',
            'reason'     => 'HTTP 422',
            'resent_by'  => 'ana@example.com',
            'contact_id' => 55,
            'step'       => ['id' => 31, 'name' => 'SMS de lembrete'],
            'campaign'   => ['id' => 7, 'name' => 'Boas-vindas', 'url' => 'https://mautic.test/campaign/7'],
        ]);

        (new ResendFailedAlert($sender, new CampaignLink($router)))->raise($log, 'sms', 'HTTP 422', 'ana@example.com');
    }
}
