<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service;

use MauticPlugin\N8nDispatchBundle\Entity\PollRun;
use MauticPlugin\N8nDispatchBundle\Service\StatusPollSettings;
use PHPUnit\Framework\TestCase;

class StatusPollSettingsTest extends TestCase
{
    public function testIsOffByDefault(): void
    {
        $this->assertSame(['enabled' => false, 'interval' => 60], StatusPollSettings::fromArray([]));
    }

    /**
     * @dataProvider enabledValues
     */
    public function testEnabledAcceptsWhatTheFormStores(mixed $stored, bool $expected): void
    {
        $this->assertSame($expected, StatusPollSettings::fromArray([StatusPollSettings::KEY_ENABLED => $stored])['enabled']);
    }

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function enabledValues(): iterable
    {
        yield 'int 1'     => [1, true];
        yield 'string 1'  => ['1', true];
        yield 'true'      => [true, true];
        yield 'int 0'     => [0, false];
        yield 'string 0'  => ['0', false];
        yield 'false'     => [false, false];
        yield 'null'      => [null, false];
        yield 'empty'     => ['', false];
    }

    /**
     * @dataProvider intervals
     */
    public function testIntervalIsKeptInsideTheAllowedRange(mixed $stored, int $expected): void
    {
        $this->assertSame($expected, StatusPollSettings::fromArray([StatusPollSettings::KEY_INTERVAL => $stored])['interval']);
    }

    /**
     * @return iterable<string, array{mixed, int}>
     */
    public static function intervals(): iterable
    {
        yield 'one hour'        => [60, 60];
        yield 'two hours'       => [120, 120];
        yield 'numeric string'  => ['120', 120];
        yield 'the minimum'     => [5, 5];
        yield 'below minimum'   => [1, 5];
        yield 'zero'            => [0, 5];
        yield 'negative'        => [-30, 5];
        yield 'one day'         => [1440, 1440];
        yield 'above maximum'   => [100000, 1440];
        yield 'not a number'    => ['abc', 60];
        yield 'empty'           => ['', 60];
        yield 'null'            => [null, 60];
    }

    private function runStartedAt(string $when): PollRun
    {
        return PollRun::start(new \DateTimeImmutable($when));
    }

    public function testNeverStaleBeforeTheFirstRun(): void
    {
        $this->assertFalse(StatusPollSettings::isStale(null, 60, new \DateTimeImmutable('2026-10-02 10:00:00')));
    }

    public function testNotStaleWhileTheLastRunIsRecent(): void
    {
        $this->assertFalse(StatusPollSettings::isStale($this->runStartedAt('2026-10-02 08:00:00'), 60, new \DateTimeImmutable('2026-10-02 10:00:00')));
    }

    public function testStaleWhenFarLongerThanTheInterval(): void
    {
        // 60 min interval: stale after 3 hours without a run
        $this->assertTrue(StatusPollSettings::isStale($this->runStartedAt('2026-10-02 06:00:00'), 60, new \DateTimeImmutable('2026-10-02 10:00:00')));
    }

    public function testAShortIntervalStillWaitsAtLeastAnHourBeforeWarning(): void
    {
        $this->assertFalse(StatusPollSettings::isStale($this->runStartedAt('2026-10-02 09:30:00'), 5, new \DateTimeImmutable('2026-10-02 10:00:00')));
        $this->assertTrue(StatusPollSettings::isStale($this->runStartedAt('2026-10-02 08:30:00'), 5, new \DateTimeImmutable('2026-10-02 10:00:00')));
    }
}
