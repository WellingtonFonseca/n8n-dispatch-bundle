<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service;

use MauticPlugin\N8nDispatchBundle\Entity\PollRun;
use MauticPlugin\N8nDispatchBundle\Service\FeatureSettings;
use MauticPlugin\N8nDispatchBundle\Service\StatusPollSettings;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('area:status-poll')]
#[Group('risk:CRITICO')]
#[Group('risk:FLUXO')]
class StatusPollSettingsTest extends TestCase
{
    public function testTheCurrentSettingsAreReadFromTheIntegrationFeatures(): void
    {
        $settings = $this->createStub(\Mautic\PluginBundle\Entity\Integration::class);
        $settings->method('getFeatureSettings')->willReturn(['status_poll_enabled' => 1, 'status_poll_batch' => 40, 'status_poll_timeout' => 5000]);
        $integration = $this->createStub(\MauticPlugin\N8nDispatchBundle\Integration\N8nDispatchIntegration::class);
        $integration->method('getIntegrationSettings')->willReturn($settings);
        $helper = $this->createMock(\Mautic\PluginBundle\Helper\IntegrationHelper::class);
        $helper->method('getIntegrationObject')->with('N8nDispatch')->willReturn($integration);
        $none = $this->createStub(\Mautic\PluginBundle\Helper\IntegrationHelper::class);
        $none->method('getIntegrationObject')->willReturn(null);

        $this->assertSame(
            ['enabled' => true, 'interval' => 60, 'batch' => 40, 'timeout' => 900, 'delay' => 1000, 'maxDuration' => 900],
            (new StatusPollSettings(new FeatureSettings($helper)))->current()
        );
        $this->assertSame(StatusPollSettings::fromArray([]), (new StatusPollSettings(new FeatureSettings($none)))->current());
    }

    public function testIsOffByDefault(): void
    {
        $this->assertSame(
            ['enabled' => false, 'interval' => 60, 'batch' => 100, 'timeout' => 180, 'delay' => 1000, 'maxDuration' => 300],
            StatusPollSettings::fromArray([])
        );
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

    public function testThePauseBetweenCallsIsInMillisecondsAndKeptInsideTheAllowedRange(): void
    {
        $this->assertSame(1000, StatusPollSettings::fromArray([])['delay'], 'one second by default');
        $this->assertSame(0, StatusPollSettings::fromArray([StatusPollSettings::KEY_DELAY => 0])['delay'], '0 is no pause');
        $this->assertSame(2500, StatusPollSettings::fromArray([StatusPollSettings::KEY_DELAY => '2500'])['delay']);
        $this->assertSame(0, StatusPollSettings::fromArray([StatusPollSettings::KEY_DELAY => -5])['delay']);
        $this->assertSame(60000, StatusPollSettings::fromArray([StatusPollSettings::KEY_DELAY => 999999])['delay']);
        $this->assertSame(1000, StatusPollSettings::fromArray([StatusPollSettings::KEY_DELAY => 'abc'])['delay']);
    }

    /**
     * @dataProvider batches
     */
    public function testBatchSizeIsKeptInsideTheAllowedRange(mixed $stored, int $expected): void
    {
        $this->assertSame($expected, StatusPollSettings::fromArray([StatusPollSettings::KEY_BATCH => $stored])['batch']);
    }

    /**
     * @return iterable<string, array{mixed, int}>
     */
    public static function batches(): iterable
    {
        yield 'default'       => [100, 100];
        yield 'fifty'         => ['50', 50];
        yield 'the minimum'   => [10, 10];
        yield 'below minimum' => [1, 10];
        yield 'zero'          => [0, 10];
        yield 'the maximum'   => [1000, 1000];
        yield 'above maximum' => [50000, 1000];
        yield 'not a number'  => ['abc', 100];
        yield 'null'          => [null, 100];
    }

    /**
     * @dataProvider timeouts
     */
    public function testTimeoutIsKeptInsideTheAllowedRange(mixed $stored, int $expected): void
    {
        $this->assertSame($expected, StatusPollSettings::fromArray([StatusPollSettings::KEY_TIMEOUT => $stored])['timeout']);
    }

    /**
     * @return iterable<string, array{mixed, int}>
     */
    public static function timeouts(): iterable
    {
        yield 'default'       => [180, 180];
        yield 'two minutes'   => ['120', 120];
        yield 'the minimum'   => [10, 10];
        yield 'below minimum' => [2, 10];
        yield 'negative'      => [-5, 10];
        yield 'the maximum'   => [900, 900];
        yield 'above maximum' => [99999, 900];
        yield 'not a number'  => ['x', 180];
        yield 'null'          => [null, 180];
    }

    public function testTheTotalDurationIsNeverShorterThanTheTimeout(): void
    {
        $settings = StatusPollSettings::fromArray([
            StatusPollSettings::KEY_TIMEOUT      => 400,
            StatusPollSettings::KEY_MAX_DURATION => 100,
        ]);

        $this->assertSame(400, $settings['timeout']);
        $this->assertSame(400, $settings['maxDuration']);
    }

    public function testTheTotalDurationIsKeptInsideTheAllowedRange(): void
    {
        $this->assertSame(300, StatusPollSettings::fromArray([StatusPollSettings::KEY_MAX_DURATION => 'abc'])['maxDuration']);
        $this->assertSame(1800, StatusPollSettings::fromArray([StatusPollSettings::KEY_MAX_DURATION => 99999])['maxDuration']);
        $this->assertSame(240, StatusPollSettings::fromArray([StatusPollSettings::KEY_MAX_DURATION => 240])['maxDuration']);
    }

    public function testStalenessBoundariesAreExactlyThreeIntervalsAndAtLeastAnHour(): void
    {
        $now = new \DateTimeImmutable('2026-10-02 10:00:00');

        // 30 min interval: 3 x 30 = 90 minutes
        $this->assertFalse(StatusPollSettings::isStale($this->runStartedAt('2026-10-02 08:30:00'), 30, $now), 'exactly 90 minutes ago is not yet stale');
        $this->assertTrue(StatusPollSettings::isStale($this->runStartedAt('2026-10-02 08:29:00'), 30, $now));
        // 5 min interval: the one hour floor
        $this->assertFalse(StatusPollSettings::isStale($this->runStartedAt('2026-10-02 09:00:00'), 5, $now), 'exactly 60 minutes ago is not yet stale');
        $this->assertTrue(StatusPollSettings::isStale($this->runStartedAt('2026-10-02 08:59:00'), 5, $now));
        // 25 min interval: 75 minutes, so 3 x the interval wins over the floor and 2 x would not
        $this->assertFalse(StatusPollSettings::isStale($this->runStartedAt('2026-10-02 08:50:00'), 25, $now));
    }
}
