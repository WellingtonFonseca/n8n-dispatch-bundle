<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Entity;

use MauticPlugin\N8nDispatchBundle\Entity\PollRun;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('area:status-poll')]
#[Group('risk:DADOS')]
class PollRunTest extends TestCase
{
    public function testStartsRunning(): void
    {
        $run = PollRun::start(new \DateTimeImmutable('2026-10-02 10:00:00'));

        $this->assertSame(PollRun::STATUS_RUNNING, $run->getStatus());
        $this->assertNull($run->getFinishedAt());
        $this->assertNull($run->getSummary());
    }
}
