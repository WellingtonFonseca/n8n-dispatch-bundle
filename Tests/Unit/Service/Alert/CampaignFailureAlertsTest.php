<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service\Alert;

use Doctrine\Common\Collections\ArrayCollection;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\Entity\EventRepository;
use Mautic\CampaignBundle\Entity\FailedLeadEventLog;
use Mautic\CampaignBundle\Entity\LeadEventLog;
use MauticPlugin\N8nDispatchBundle\Service\Alert\AlertSender;
use MauticPlugin\N8nDispatchBundle\Service\Alert\AlertSettings;
use MauticPlugin\N8nDispatchBundle\Service\Alert\AlertThrottle;
use MauticPlugin\N8nDispatchBundle\Service\Alert\CampaignFailureAlerts;
use MauticPlugin\N8nDispatchBundle\Service\Alert\CampaignLink;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations] // setUp builds the doubles; each test uses the ones it needs
class CampaignFailureAlertsTest extends TestCase
{
    /** @var AlertSender&MockObject */
    private AlertSender $sender;

    /** @var AlertThrottle&MockObject */
    private AlertThrottle $throttle;

    protected function setUp(): void
    {
        $this->sender   = $this->createMock(AlertSender::class);
        $this->throttle = $this->createMock(AlertThrottle::class);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function alerts(int $failedCount, array $settings = [AlertSettings::KEY_ENABLED => 1]): CampaignFailureAlerts
    {
        $current = $this->createStub(AlertSettings::class);
        $current->method('current')->willReturn(AlertSettings::fromArray($settings));
        $events = $this->createStub(EventRepository::class);
        $events->method('getFailedCount')->willReturn($failedCount);
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $name, array $params, int $type): string => 'https://mautic.test/'.$name.'/'.$params['objectAction'].'/'.$params['objectId']);

        return new CampaignFailureAlerts($this->sender, $current, $events, new CampaignLink($router), $this->throttle);
    }

    private function log(bool $published, int $contacts, ?string $reason = 'HTTP 422'): LeadEventLog
    {
        $campaign = $this->createStub(Campaign::class);
        $campaign->method('getId')->willReturn(7);
        $campaign->method('getName')->willReturn('Boas-vindas');
        $campaign->method('isPublished')->willReturn($published);
        $campaign->method('getLeads')->willReturn(new ArrayCollection(array_fill(0, $contacts, new \stdClass())));
        $step = $this->createStub(Event::class);
        $step->method('getId')->willReturn(31);
        $step->method('getName')->willReturn('Email de boas-vindas');
        $step->method('getType')->willReturn('n8ndispatch.email.send');
        $step->method('getCampaign')->willReturn($campaign);
        $failed = $this->createStub(FailedLeadEventLog::class);
        $failed->method('getReason')->willReturn($reason);
        $log = $this->createStub(LeadEventLog::class);
        $log->method('getEvent')->willReturn($step);
        $log->method('getFailedLog')->willReturn(null === $reason ? null : $failed);

        return $log;
    }

    public function testACampaignSwitchedOffByTheFailureRaisesTheUnpublishedAlertWithTheNumbers(): void
    {
        $this->sender->expects($this->once())->method('send')->with('campaign.unpublished', [
            'campaign'           => ['id' => 7, 'name' => 'Boas-vindas', 'url' => 'https://mautic.test/mautic_campaign_action/view/7'],
            'step'               => ['id' => 31, 'name' => 'Email de boas-vindas', 'type' => 'n8ndispatch.email.send'],
            'failed_count'       => 3,
            'contact_count'      => 20,
            'failed_percent'     => 15.0,
            'core_limit_percent' => 35,
            'last_reason'        => 'HTTP 422',
        ]);

        $this->alerts(3)->afterFailure($this->log(false, 20), true);
    }

    public function testThePercentIsRoundedToOneDecimal(): void
    {
        $this->sender->expects($this->once())->method('send')->with('campaign.unpublished', $this->callback(static fn (array $d): bool => 33.3 === $d['failed_percent']));

        $this->alerts(1)->afterFailure($this->log(false, 3), true);
    }

    public function testThePercentOfTheWarningIsRoundedToo(): void
    {
        $this->sender->expects($this->once())->method('send')->with('campaign.failure_warning', $this->callback(static fn (array $d): bool => 5.8 === $d['failed_percent']), 'warning:7', 86400);

        $this->alerts(7)->afterFailure($this->log(true, 121), true);
    }

    public function testACampaignThatWasAlreadyOffRaisesNothing(): void
    {
        $this->sender->expects($this->never())->method('send');

        $this->alerts(3)->afterFailure($this->log(false, 20), false);
    }

    public function testAnEmptyCampaignCountsAsAllFailedAndHasNoReasonWhenCoreKeptNone(): void
    {
        $this->sender->expects($this->once())->method('send')->with('campaign.unpublished', $this->callback(
            static fn (array $d): bool => 100.0 === $d['failed_percent'] && null === $d['last_reason']
        ));

        $this->alerts(1)->afterFailure($this->log(false, 0, null), true);
    }

    public function testAWarningGoesOutOnceADayPerCampaignWhenTheShareReachesTheWarningPercent(): void
    {
        $this->sender->expects($this->once())->method('send')->with(
            'campaign.failure_warning',
            $this->callback(static fn (array $d): bool => 5.0 === $d['failed_percent'] && 5 === $d['warning_percent'] && 100 === $d['contact_count']),
            'warning:7',
            86400
        );

        $this->alerts(5)->afterFailure($this->log(true, 100), true);
    }

    public function testBelowTheWarningPercentNothingIsRaised(): void
    {
        $this->sender->expects($this->never())->method('send');

        $this->alerts(4)->afterFailure($this->log(true, 100), true);
    }

    public function testAWarningHonoursThePercentOfTheSettings(): void
    {
        $this->sender->expects($this->once())->method('send')->with('campaign.failure_warning', $this->anything(), 'warning:7', 86400);

        $this->alerts(2, [AlertSettings::KEY_ENABLED => 1, AlertSettings::KEY_WARNING_PERCENT => 2, AlertSettings::KEY_WARNING_MIN => 20])->afterFailure($this->log(true, 100), true);
    }

    public function testASmallCampaignGetsNoWarningBelowTheMinimumOfContacts(): void
    {
        $this->sender->expects($this->never())->method('send');

        $this->alerts(5)->afterFailure($this->log(true, 99), true); // 5.05%: over the percent, under the 100 contacts core 7 needs to switch off
    }

    public function testTheMinimumOfContactsItselfIsEnoughForAWarning(): void
    {
        $this->sender->expects($this->once())->method('send');

        $this->alerts(5)->afterFailure($this->log(true, 100), true);
    }

    public function testWhileTheWarningIsCoolingDownNoQueryIsMadeForThePercent(): void
    {
        $this->throttle->expects($this->once())->method('isTaken')->with('warning:7')->willReturn(true);
        $this->sender->expects($this->never())->method('send');
        $events = $this->createMock(EventRepository::class);
        $events->expects($this->never())->method('getFailedCount');
        $current = $this->createStub(AlertSettings::class);
        $current->method('current')->willReturn(AlertSettings::fromArray([AlertSettings::KEY_ENABLED => 1]));

        (new CampaignFailureAlerts($this->sender, $current, $events, new CampaignLink($this->createStub(UrlGeneratorInterface::class)), $this->throttle))->afterFailure($this->log(true, 100), true);
    }

    public function testTheUnpublishedAlertDoesNotWaitForTheWarningCooldown(): void
    {
        $this->throttle->method('isTaken')->willReturn(true);
        $this->sender->expects($this->once())->method('send')->with('campaign.unpublished', $this->anything());

        $this->alerts(3)->afterFailure($this->log(false, 20), true);
    }

    public function testWithTheChannelOffTheAlertIsStillRaisedSoTheSenderCanLogIt(): void
    {
        $this->sender->expects($this->once())->method('send')->with('campaign.unpublished', $this->anything());

        $this->alerts(3, [])->afterFailure($this->log(false, 20), true);
    }
}
