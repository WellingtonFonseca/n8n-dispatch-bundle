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
        $this->assertSame(
            ['enabled' => false, 'interval' => 60, 'batch' => 100, 'timeout' => 180, 'maxDuration' => 300],
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

    public function testTheLastRunBlockIsJsonWithOneObjectPerChannel(): void
    {
        $json = StatusPollSettings::lastRunJson(
            new \DateTimeImmutable('2026-10-04 03:29:00', new \DateTimeZone('UTC')),
            'OK',
            "email: asked 1, changed 1, unchanged 0, unknown 0, invalid 0, 1 calls\nsms: asked 0, changed 0, unchanged 0, unknown 0, invalid 0, 0 calls",
            null
        );

        $this->assertSame([
            'date'   => '2026-10-04 03:29 UTC',
            'status' => 'OK',
            'email'  => ['asked' => 1, 'changed' => 1, 'unchanged' => 0, 'unknown' => 0, 'invalid' => 0, 'calls' => 1],
            'sms'    => ['asked' => 0, 'changed' => 0, 'unchanged' => 0, 'unknown' => 0, 'invalid' => 0, 'calls' => 0],
        ], json_decode($json, true));
        $this->assertStringContainsString("\n    \"email\": {", $json, 'written out, one key per line');
    }

    public function testAChannelThatFailedShowsItsError(): void
    {
        $json = StatusPollSettings::lastRunJson(
            new \DateTimeImmutable('2026-10-04 03:29:00', new \DateTimeZone('UTC')),
            'Erro',
            "email: asked 1, changed 1, unchanged 0, unknown 0, invalid 0, 1 calls\nsms: webhook returned HTTP 500.",
            null
        );

        $this->assertSame(['error' => 'webhook returned HTTP 500.'], json_decode($json, true)['sms']);
    }

    public function testALineThatIsNotAChannelIsKeptAsAMessage(): void
    {
        $json = StatusPollSettings::lastRunJson(new \DateTimeImmutable('2026-10-04 03:29:00', new \DateTimeZone('UTC')), 'Erro', 'boom', null);

        $this->assertSame('boom', json_decode($json, true)['message']);
    }

    public function testARunWithNoSummaryHasOnlyTheDateAndTheStatus(): void
    {
        $json = StatusPollSettings::lastRunJson(new \DateTimeImmutable('2026-10-04 03:29:00', new \DateTimeZone('UTC')), 'Rodando', null, null);

        $this->assertSame(['date' => '2026-10-04 03:29 UTC', 'status' => 'Rodando'], json_decode($json, true));
    }

    public function testAStaleRunCarriesTheWarningRightAfterTheStatus(): void
    {
        $json = StatusPollSettings::lastRunJson(new \DateTimeImmutable('2026-10-04 03:29:00', new \DateTimeZone('UTC')), 'OK', null, 'ATENÇÃO: sem rodadas há muito tempo.');

        $this->assertSame(['date', 'status', 'warning'], array_keys(json_decode($json, true)));
        $this->assertStringContainsString('ATENÇÃO', $json, 'unicode is not escaped');
    }
}
