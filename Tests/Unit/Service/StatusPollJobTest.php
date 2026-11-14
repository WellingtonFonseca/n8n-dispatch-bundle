<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service;

use MauticPlugin\N8nDispatchBundle\Entity\PollRun;
use MauticPlugin\N8nDispatchBundle\Service\StatusPollJob;
use MauticPlugin\N8nDispatchBundle\Service\StatusPollSettings;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('area:status-poll')]
#[Group('risk:INFRA')]
class StatusPollJobTest extends TestCase
{
    public function testTheJobIsDueWithTheIntervalOnlyWhenOn(): void
    {
        $settings = $this->createMock(StatusPollSettings::class);
        $settings->method('current')->willReturnOnConsecutiveCalls(['enabled' => false, 'interval' => 60], ['enabled' => true, 'interval' => 120]);
        $job = new StatusPollJob($settings);

        $this->assertNull($job->intervalMinutes());
        $this->assertSame(120, $job->intervalMinutes());
        $this->assertSame(PollRun::KIND_STATUS_POLL, $job->kind());
        $this->assertSame('n8ndispatch:status:poll', $job->command());
    }
}
