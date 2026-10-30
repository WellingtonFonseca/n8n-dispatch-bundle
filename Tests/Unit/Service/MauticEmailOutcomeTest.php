<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service;

use MauticPlugin\N8nDispatchBundle\Service\MauticEmailOutcome;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MauticEmailOutcomeTest extends TestCase
{
    public function testOnlyTheModeMauticIsADirectDispatch(): void
    {
        $this->assertTrue(MauticEmailOutcome::isDirect(['n8ndispatch' => ['mode' => 'mautic']]));
        $this->assertFalse(MauticEmailOutcome::isDirect(['n8ndispatch' => ['mode' => 'n8n']]));
        $this->assertFalse(MauticEmailOutcome::isDirect(['n8ndispatch' => ['status' => 'production']]));
        $this->assertFalse(MauticEmailOutcome::isDirect(['n8ndispatch' => 'mautic']));
        $this->assertFalse(MauticEmailOutcome::isDirect([]));
    }

    /**
     * @dataProvider statIds
     *
     * @param array<string, mixed> $metadata
     * @param list<int>            $expected
     */
    #[DataProvider('statIds')]
    public function testTheStatIdsOfADispatch(array $metadata, array $expected): void
    {
        $this->assertSame($expected, MauticEmailOutcome::statIdsOf($metadata));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, list<int>}>
     */
    public static function statIds(): iterable
    {
        yield 'the list'                   => [['n8ndispatch' => ['statIds' => [3, 7]]], [3, 7]];
        yield 'the list wins over the one' => [['n8ndispatch' => ['statIds' => [9], 'statId' => 4]], [9]];
        yield 'the first send only'        => [['n8ndispatch' => ['statId' => 4]], [4]];
        yield 'oldest first'               => [['n8ndispatch' => ['statIds' => [8, 2, 5]]], [2, 5, 8]];
        yield 'no repeats'                 => [['n8ndispatch' => ['statIds' => [2, 2, '2']]], [2]];
        yield 'text ids'                   => [['n8ndispatch' => ['statIds' => ['5', 6]]], [5, 6]];
        yield 'junk is dropped'            => [['n8ndispatch' => ['statIds' => [0, -1, 'x', null, 3]]], [3]];
        yield 'queued'                     => [['n8ndispatch' => ['statId' => null]], []];
        yield 'no block'                   => [[], []];
        yield 'not an array'               => [['n8ndispatch' => 'x'], []];
    }

    /**
     * @dataProvider statFieldCases
     *
     * @param array<string, mixed>                $metadata
     * @param array{statId: ?int, statIds?: list<int>} $expected
     */
    #[DataProvider('statFieldCases')]
    public function testTheStatFieldsOfADispatchOnceOneMoreAttemptRan(array $metadata, ?int $new, array $expected): void
    {
        $this->assertSame($expected, MauticEmailOutcome::statFields($metadata, $new));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, ?int, array<string, mixed>}>
     */
    public static function statFieldCases(): iterable
    {
        yield 'the first send'              => [[], 10, ['statId' => 10]];
        yield 'the first send was held'     => [[], null, ['statId' => null]];
        yield 'a second attempt'            => [['n8ndispatch' => ['statId' => 10]], 11, ['statId' => 10, 'statIds' => [10, 11]]];
        yield 'a third attempt'             => [['n8ndispatch' => ['statId' => 10, 'statIds' => [10, 11]]], 12, ['statId' => 10, 'statIds' => [10, 11, 12]]];
        yield 'an attempt that made none'   => [['n8ndispatch' => ['statId' => 10]], null, ['statId' => 10]];
        yield 'the same Stat twice'         => [['n8ndispatch' => ['statId' => 10]], 10, ['statId' => 10]];
        yield 'the first made none'         => [['n8ndispatch' => ['statId' => null]], 12, ['statId' => 12]];
        yield 'older ones first'            => [['n8ndispatch' => ['statIds' => [20, 5]]], 30, ['statId' => 5, 'statIds' => [5, 20, 30]]];
    }

    /**
     * @dataProvider badges
     */
    #[DataProvider('badges')]
    public function testTheBadgeOfAState(string $state, string $badge): void
    {
        $this->assertSame($badge, MauticEmailOutcome::badgeOf($state));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function badges(): iterable
    {
        yield 'queued'       => ['queued', 'pending'];
        yield 'sent'         => ['sent', 'success'];
        yield 'opened'       => ['opened', 'success'];
        yield 'clicked'      => ['clicked', 'success'];
        yield 'failed'       => ['failed', 'failed'];
        yield 'bounced'      => ['bounced', 'failed'];
        yield 'skipped'      => ['skipped', 'failed'];
        yield 'unsubscribed' => ['unsubscribed', 'paused'];
    }

    public function testTheOutcomeTheStepRecorded(): void
    {
        $this->assertSame('failed', MauticEmailOutcome::outcomeOf(['n8ndispatch' => ['outcome' => 'failed']]));
        $this->assertNull(MauticEmailOutcome::outcomeOf(['n8ndispatch' => ['outcome' => 3]]));
        $this->assertNull(MauticEmailOutcome::outcomeOf(['n8ndispatch' => 'x']));
        $this->assertNull(MauticEmailOutcome::outcomeOf([]));
    }

    /**
     * @dataProvider results
     */
    #[DataProvider('results')]
    public function testTheResultOfAState(string $state, string $result): void
    {
        $this->assertSame($result, MauticEmailOutcome::resultOf($state));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function results(): iterable
    {
        yield 'sent'         => ['sent', 'success'];
        yield 'opened'       => ['opened', 'success'];
        yield 'clicked'      => ['clicked', 'success'];
        yield 'unsubscribed' => ['unsubscribed', 'success'];
        yield 'failed'       => ['failed', 'error'];
        yield 'bounced'      => ['bounced', 'error'];
        yield 'skipped'      => ['skipped', 'error'];
        yield 'queued'       => ['queued', 'pending'];
    }
}
