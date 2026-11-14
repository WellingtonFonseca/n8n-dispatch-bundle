<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service;

use MauticPlugin\N8nDispatchBundle\Service\MirrorResyncLogRows;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('area:mirror')]
#[Group('risk:DADOS')]
class MirrorResyncLogRowsTest extends TestCase
{
    public function testASummaryLineBecomesTheCountsOfTheRow(): void
    {
        $rows = MirrorResyncLogRows::build([['started_at' => '2026-11-13 10:00:00', 'status' => 'ok', 'summary' => 'due 9, calls 8, ok 6, failed 2, skipped 1, left 3']]);

        $this->assertSame(
            ['started_at' => '2026-11-13 10:00:00', 'status' => 'ok', 'detail' => '', 'due' => 9, 'calls' => 8, 'ok' => 6, 'failed' => 2, 'skipped' => 1, 'left' => 3],
            $rows[0]
        );
    }

    public function testTheErrorOfASummaryIsTheDetail(): void
    {
        $rows = MirrorResyncLogRows::build([['started_at' => 'x', 'status' => 'error', 'summary' => 'due 1, calls 1, ok 0, failed 1, skipped 0, left 0; error: HTTP 500']]);

        $this->assertSame('HTTP 500', $rows[0]['detail']);
        $this->assertSame(1, $rows[0]['failed']);
    }

    public function testATextThatIsNotASummaryIsTheDetailWithNoCounts(): void
    {
        $rows = MirrorResyncLogRows::build([['started_at' => 'x', 'status' => 'error', 'summary' => 'Connection refused']]);

        $this->assertSame('Connection refused', $rows[0]['detail']);
        $this->assertNull($rows[0]['due']);
    }

    public function testARunStillRunningHasNoCounts(): void
    {
        $rows = MirrorResyncLogRows::build([['started_at' => 'x', 'status' => 'running', 'summary' => null]]);

        $this->assertSame('', $rows[0]['detail']);
        $this->assertNull($rows[0]['left']);
    }

    public function testNoRunsGiveNoRowsAndTheOrderIsKept(): void
    {
        $this->assertSame([], MirrorResyncLogRows::build([]));
        $rows = MirrorResyncLogRows::build([['started_at' => 'b', 'status' => 'ok', 'summary' => null], ['started_at' => 'a', 'status' => 'ok', 'summary' => null]]);
        $this->assertSame(['b', 'a'], array_column($rows, 'started_at'));
    }
}
