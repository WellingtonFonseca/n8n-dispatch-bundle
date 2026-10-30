<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service;

use MauticPlugin\N8nDispatchBundle\Service\MauticEmailOutcome;
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
