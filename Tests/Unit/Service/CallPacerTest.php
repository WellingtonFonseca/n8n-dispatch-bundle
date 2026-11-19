<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service;

use MauticPlugin\N8nDispatchBundle\Service\CallPacer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('area:status-poll')]
#[Group('area:mirror')]
#[Group('risk:CRITICO')]
#[Group('risk:FLUXO')]
class CallPacerTest extends TestCase
{
    /** @var list<int> */
    private array $slept = [];

    private function pacer(): CallPacer
    {
        return new CallPacer(function (int $ms): void { $this->slept[] = $ms; });
    }

    /** A call to n8n that takes $ms: the time passes between pause() and finished(). */
    private function call(CallPacer $pacer, int $delayMs, int $takesMs): void
    {
        $pacer->pause($delayMs);
        usleep($takesMs * 1000);
        $pacer->finished();
    }

    public function testThePauseIsCountedFromTheEndOfThePreviousCall(): void
    {
        $pacer = $this->pacer();

        $this->call($pacer, 400, 250);
        $pacer->pause(400);

        // counted from the start of the call it would be about 150 ms; from its end it is the whole pause
        $this->assertCount(1, $this->slept);
        $this->assertGreaterThan(380, $this->slept[0]);
        $this->assertLessThanOrEqual(400, $this->slept[0]);
    }

    public function testACallSlowerThanThePauseStillGetsTheWholePauseAfterIt(): void
    {
        $pacer = $this->pacer();

        $this->call($pacer, 100, 180);
        $pacer->pause(100);

        $this->assertCount(1, $this->slept, 'from the start of the call the pause was already over and the next call went out at once');
        $this->assertGreaterThan(80, $this->slept[0]);
    }

    public function testThereIsNoPauseBeforeTheFirstCallNorWithAZeroDelay(): void
    {
        $pacer = $this->pacer();

        $this->call($pacer, 5000, 0);
        $this->assertSame([], $this->slept, 'no pause before the first call');

        $pacer->pause(0);
        $this->assertSame([], $this->slept, 'a zero delay never sleeps');
    }

    public function testACallerThatNeverSaysFinishedStillGetsAPauseCountedFromTheStartOfTheCall(): void
    {
        $pacer = $this->pacer();

        $pacer->pause(300);
        usleep(100000);
        $pacer->pause(300);

        // from the start of the call about 200 ms are left, from its end 300: the margin of usleep on a loaded machine is not asserted
        $this->assertCount(1, $this->slept);
        $this->assertGreaterThan(0, $this->slept[0]);
        $this->assertLessThan(300, $this->slept[0], 'not the whole pause: it was counted from the start of the call');
    }
}
