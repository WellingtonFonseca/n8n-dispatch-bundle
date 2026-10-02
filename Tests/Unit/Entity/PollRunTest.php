<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Entity;

use MauticPlugin\N8nDispatchBundle\Entity\PollRun;
use PHPUnit\Framework\TestCase;

class PollRunTest extends TestCase
{
    public function testStartsRunning(): void
    {
        $run = PollRun::start(new \DateTimeImmutable('2026-10-02 10:00:00'));

        $this->assertSame(PollRun::STATUS_RUNNING, $run->getStatus());
        $this->assertNull($run->getFinishedAt());
        $this->assertNull($run->getSummary());
    }

    public function testFinishRecordsTheOutcome(): void
    {
        $run = PollRun::start(new \DateTimeImmutable('2026-10-02 10:00:00'));
        $end = new \DateTimeImmutable('2026-10-02 10:00:03');

        $run->finish(PollRun::STATUS_OK, 'email: asked 1', $end);

        $this->assertSame(PollRun::STATUS_OK, $run->getStatus());
        $this->assertSame('email: asked 1', $run->getSummary());
        $this->assertEquals($end, $run->getFinishedAt());
    }

    public function testRejectsAnUnknownStatus(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PollRun::start(new \DateTimeImmutable())->finish('weird', null, new \DateTimeImmutable());
    }
}
