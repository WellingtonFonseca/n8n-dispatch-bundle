<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service\Alert;

use MauticPlugin\N8nDispatchBundle\Service\Alert\AlertSender;
use MauticPlugin\N8nDispatchBundle\Service\Alert\AlertSettings;
use MauticPlugin\N8nDispatchBundle\Service\Alert\AlertThrottle;
use MauticPlugin\N8nDispatchBundle\Service\Alert\BounceSpikeAlerts;
use MauticPlugin\N8nDispatchBundle\Service\Alert\BounceSpikeReader;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class BounceSpikeAlertsTest extends TestCase
{
    /** @var AlertThrottle&MockObject */
    private AlertThrottle $throttle;

    /** @var AlertSender&MockObject */
    private AlertSender $sender;

    /** @var BounceSpikeReader&MockObject */
    private BounceSpikeReader $reader;

    protected function setUp(): void
    {
        $this->throttle = $this->createMock(AlertThrottle::class);
        $this->sender   = $this->createMock(AlertSender::class);
        $this->reader   = $this->createMock(BounceSpikeReader::class);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function alerts(array $settings = [AlertSettings::KEY_ENABLED => 1]): BounceSpikeAlerts
    {
        $current = $this->createStub(AlertSettings::class);
        $current->method('current')->willReturn(AlertSettings::fromArray($settings));
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $name, array $params, int $type): string => 'https://mautic.test/'.$name.'/'.$params['objectAction'].'/'.$params['objectId'].'/'.$type);

        return new BounceSpikeAlerts($current, $this->throttle, $this->sender, $this->reader, $router);
    }

    public function testEachOffendingCampaignAlertsOncePerSixHoursWithTheLimitsOfTheSettings(): void
    {
        $this->throttle->method('claim')->willReturn(true);
        $this->reader->method('offenders')->willReturn([
            ['campaign_id' => 7, 'name' => 'A', 'sends' => 100, 'bounces' => 12, 'percent' => 12.0],
            ['campaign_id' => 8, 'name' => 'B', 'sends' => 50, 'bounces' => 9, 'percent' => 18.0],
        ]);
        $sent = [];
        $this->sender->method('send')->willReturnCallback(function (string $type, array $data, ?string $key, int $cooldown) use (&$sent): bool {
            $sent[] = [$type, $data, $key, $cooldown];

            return true;
        });

        $this->assertSame(2, $this->alerts()->check(new \DateTimeImmutable('2026-03-10 12:00:00')));

        $this->assertSame('campaign.bounce_spike', $sent[0][0]);
        $this->assertSame(['campaign_id' => 7, 'name' => 'A', 'sends' => 100, 'bounces' => 12, 'percent' => 12.0, 'window_minutes' => 60, 'limit_percent' => 10, 'url' => 'https://mautic.test/mautic_campaign_action/view/7/'.UrlGeneratorInterface::ABSOLUTE_URL], $sent[0][1]);
        $this->assertSame(['bounce_spike:7', 21600], [$sent[0][2], $sent[0][3]]);
        $this->assertSame('bounce_spike:8', $sent[1][2]);
    }

    public function testTheReaderGetsTheWindowTheMinimumOfSendsAndThePercentOfTheSettings(): void
    {
        $this->throttle->method('claim')->willReturn(true);
        $this->reader->expects($this->once())->method('offenders')
            ->with(new \DateTimeImmutable('2026-03-10 10:30:00'), 300, 15)
            ->willReturn([]);

        $this->alerts([AlertSettings::KEY_ENABLED => 1, AlertSettings::KEY_BOUNCE_WINDOW => 90, AlertSettings::KEY_BOUNCE_MIN_SENDS => 300, AlertSettings::KEY_BOUNCE_PERCENT => 15])
            ->check(new \DateTimeImmutable('2026-03-10 12:00:00'));
    }

    public function testTheCheckItselfRunsAtMostEveryFiveMinutes(): void
    {
        $this->throttle->expects($this->once())->method('claim')->with('bounce_check', 300)->willReturn(false);
        $this->reader->expects($this->never())->method('offenders');

        $this->assertSame(0, $this->alerts()->check(new \DateTimeImmutable()));
    }

    public function testWithTheChannelOffNothingIsClaimedNorRead(): void
    {
        $this->throttle->expects($this->never())->method('claim');
        $this->reader->expects($this->never())->method('offenders');

        $this->assertSame(0, $this->alerts([])->check(new \DateTimeImmutable()));
    }

    public function testAnAlertThatDidNotGoOutIsNotCounted(): void
    {
        $this->throttle->method('claim')->willReturn(true);
        $this->reader->method('offenders')->willReturn([['campaign_id' => 7, 'name' => 'A', 'sends' => 100, 'bounces' => 12, 'percent' => 12.0]]);
        $this->sender->method('send')->willReturn(false);

        $this->assertSame(0, $this->alerts()->check(new \DateTimeImmutable()));
    }
}
