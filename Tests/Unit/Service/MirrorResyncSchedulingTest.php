<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service;

use MauticPlugin\N8nDispatchBundle\Entity\PollRun;
use MauticPlugin\N8nDispatchBundle\Service\BackgroundCommandLauncher;
use MauticPlugin\N8nDispatchBundle\Service\CallPacer;
use MauticPlugin\N8nDispatchBundle\Service\MirrorResyncJob;
use MauticPlugin\N8nDispatchBundle\Service\MirrorResyncSettings;
use MauticPlugin\N8nDispatchBundle\Service\MirrorSendSwitch;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The settings, the schedule and the pacing of the Mirror resync.
 */
#[Group('area:mirror')]
#[Group('risk:INFRA')]
#[Group('risk:DADOS')]
class MirrorResyncSchedulingTest extends TestCase
{
    public function testTheDefaultsAreTwoHoursAHundredTwoSecondsAndADay(): void
    {
        $this->assertSame(['interval' => 120, 'batch' => 100, 'delay' => 2000, 'refreshHours' => 24], MirrorResyncSettings::fromArray([]));
    }

    public function testTheSettingsAreKeptInsideTheirLimits(): void
    {
        $low  = MirrorResyncSettings::fromArray(['mirror_resync_interval' => 29, 'mirror_resync_batch' => 9, 'mirror_resync_delay' => -1, 'mirror_resync_refresh_hours' => 5]);
        $high = MirrorResyncSettings::fromArray(['mirror_resync_interval' => 1441, 'mirror_resync_batch' => 1001, 'mirror_resync_delay' => 60001, 'mirror_resync_refresh_hours' => 169]);
        $edge = MirrorResyncSettings::fromArray(['mirror_resync_interval' => 30, 'mirror_resync_batch' => 10, 'mirror_resync_delay' => 0, 'mirror_resync_refresh_hours' => 6]);

        $this->assertSame(['interval' => 30, 'batch' => 10, 'delay' => 0, 'refreshHours' => 6], $low);
        $this->assertSame(['interval' => 1440, 'batch' => 1000, 'delay' => 60000, 'refreshHours' => 168], $high);
        $this->assertSame(['interval' => 30, 'batch' => 10, 'delay' => 0, 'refreshHours' => 6], $edge);
    }

    public function testTheCurrentSettingsAreReadFromTheIntegrationFeatures(): void
    {
        $settings = $this->createMock(\Mautic\PluginBundle\Entity\Integration::class);
        $settings->method('getFeatureSettings')->willReturn(['mirror_resync_batch' => 40]);
        $integration = $this->createMock(\MauticPlugin\N8nDispatchBundle\Integration\N8nDispatchIntegration::class);
        $integration->method('getIntegrationSettings')->willReturn($settings);
        $helper = $this->createMock(\Mautic\PluginBundle\Helper\IntegrationHelper::class);
        $helper->method('getIntegrationObject')->with('N8nDispatch')->willReturn($integration);
        $none = $this->createMock(\Mautic\PluginBundle\Helper\IntegrationHelper::class);
        $none->method('getIntegrationObject')->willReturn(null);

        $this->assertSame(['interval' => 120, 'batch' => 40, 'delay' => 2000, 'refreshHours' => 24], (new MirrorResyncSettings(new \MauticPlugin\N8nDispatchBundle\Service\FeatureSettings($helper)))->current());
        $this->assertSame(MirrorResyncSettings::fromArray([]), (new MirrorResyncSettings(new \MauticPlugin\N8nDispatchBundle\Service\FeatureSettings($none)))->current());
    }

    public function testTheJobIsDueWithTheIntervalOnlyWhenOn(): void
    {
        $settings = $this->createMock(MirrorResyncSettings::class);
        $settings->method('current')->willReturn(['interval' => 90]);
        $sendSwitch = $this->createMock(MirrorSendSwitch::class);
        $sendSwitch->method('isOn')->willReturnOnConsecutiveCalls(false, true);
        $job = new MirrorResyncJob($settings, $sendSwitch);

        $this->assertNull($job->intervalMinutes());
        $this->assertSame(90, $job->intervalMinutes());
        $this->assertSame(PollRun::KIND_MIRROR_RESYNC, $job->kind());
        $this->assertSame('n8ndispatch:mirror:resync', $job->command());
    }

    public function testTheLauncherStartsTheResyncCommand(): void
    {
        $this->assertSame(
            "nohup '/usr/local/bin/php' '/var/www/html/bin/console' 'n8ndispatch:mirror:resync' '--run-id=7' >/dev/null 2>&1 &",
            (new BackgroundCommandLauncher('/var/www/html', '/usr/local/bin/php'))->buildShellCommand('n8ndispatch:mirror:resync', 7)
        );
    }

    public function testThePacerWaitsTheRestOfTheDelayButNotBeforeTheFirstCallNorWithZero(): void
    {
        $slept = [];
        $pacer = new CallPacer(function (int $ms) use (&$slept): void { $slept[] = $ms; });

        $pacer->pause(5000);
        $pacer->pause(5000);
        $pacer->pause(0);

        $this->assertCount(1, $slept);
        $this->assertGreaterThan(4900, $slept[0]);
        $this->assertLessThanOrEqual(5000, $slept[0]);
    }
}
