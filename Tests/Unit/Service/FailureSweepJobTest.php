<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service;

use MauticPlugin\N8nDispatchBundle\Entity\PollRun;
use MauticPlugin\N8nDispatchBundle\Service\Alert\AlertSettings;
use MauticPlugin\N8nDispatchBundle\Service\FailureSweepJob;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('area:alerts')]
#[Group('risk:INFRA')]
class FailureSweepJobTest extends TestCase
{
    public function testTheJobIsAlwaysDueWithTheIntervalOfTheSettingsEvenWithTheAlertsOff(): void
    {
        $settings = $this->createMock(AlertSettings::class);
        $settings->method('current')->willReturnOnConsecutiveCalls(
            AlertSettings::fromArray([AlertSettings::KEY_ENABLED => 0]),
            AlertSettings::fromArray([AlertSettings::KEY_ENABLED => 1, AlertSettings::KEY_FAILING_INTERVAL => 30]),
        );
        $job = new FailureSweepJob($settings);

        $this->assertSame(60, $job->intervalMinutes(), 'the cleanup must run with the alerts off');
        $this->assertSame(30, $job->intervalMinutes());
        $this->assertSame(PollRun::KIND_FAILURE_SWEEP, $job->kind());
        $this->assertSame('n8ndispatch:failure:sweep', $job->command());
    }
}
