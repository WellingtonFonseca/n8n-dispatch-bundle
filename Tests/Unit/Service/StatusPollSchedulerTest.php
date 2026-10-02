<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service;

use MauticPlugin\N8nDispatchBundle\Entity\PollRunRepository;
use MauticPlugin\N8nDispatchBundle\Service\StatusPollScheduler;
use MauticPlugin\N8nDispatchBundle\Service\StatusPollSettings;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class StatusPollSchedulerTest extends TestCase
{
    /** @var StatusPollSettings&MockObject */
    private StatusPollSettings $settings;

    /** @var PollRunRepository&MockObject */
    private PollRunRepository $runs;

    private StatusPollScheduler $scheduler;

    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->settings  = $this->createMock(StatusPollSettings::class);
        $this->runs      = $this->createMock(PollRunRepository::class);
        $this->scheduler = new StatusPollScheduler($this->settings, $this->runs);
        $this->now       = new \DateTimeImmutable('2026-10-02 10:00:00');
    }

    public function testDoesNothingWhenTheStatusPollIsOff(): void
    {
        $this->settings->method('current')->willReturn(['enabled' => false, 'interval' => 60]);
        $this->runs->expects($this->never())->method('claim');

        $this->assertNull($this->scheduler->tryStart($this->now));
    }

    public function testClaimsARunWithTheConfiguredInterval(): void
    {
        $this->settings->method('current')->willReturn(['enabled' => true, 'interval' => 120]);
        $this->runs->expects($this->once())->method('claim')->with($this->now, 120)->willReturn(42);

        $this->assertSame(42, $this->scheduler->tryStart($this->now));
    }

    public function testReturnsNullWhenItIsNotTimeYet(): void
    {
        $this->settings->method('current')->willReturn(['enabled' => true, 'interval' => 60]);
        $this->runs->method('claim')->willReturn(null);
        $this->runs->expects($this->never())->method('prune');

        $this->assertNull($this->scheduler->tryStart($this->now));
    }

    public function testOldRunsAreDroppedWhenANewOneStarts(): void
    {
        $this->settings->method('current')->willReturn(['enabled' => true, 'interval' => 60]);
        $this->runs->method('claim')->willReturn(7);
        $this->runs->expects($this->once())->method('prune')->with($this->equalTo(new \DateTimeImmutable('2026-09-25 10:00:00')));

        $this->scheduler->tryStart($this->now);
    }
}
