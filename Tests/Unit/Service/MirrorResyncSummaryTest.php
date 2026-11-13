<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service;

use MauticPlugin\N8nDispatchBundle\Service\MirrorResyncSummary;
use PHPUnit\Framework\TestCase;

class MirrorResyncSummaryTest extends TestCase
{
    public function testTheLineCarriesEveryCountInItsOwnPlace(): void
    {
        $line = MirrorResyncSummary::line(['due' => 11, 'calls' => 22, 'ok' => 33, 'failed' => 44, 'skipped' => 55, 'left' => 66, 'error' => null]);

        $this->assertSame('due 11, calls 22, ok 33, failed 44, skipped 55, left 66', $line);
    }

    public function testAnErrorIsAddedAfterTheCounts(): void
    {
        $line = MirrorResyncSummary::line(['due' => 1, 'calls' => 0, 'ok' => 0, 'failed' => 0, 'skipped' => 0, 'left' => 1, 'error' => 'no webhook']);

        $this->assertSame('due 1, calls 0, ok 0, failed 0, skipped 0, left 1; error: no webhook', $line);
    }

    public function testAWrittenLineIsReadBackAsFields(): void
    {
        $line = MirrorResyncSummary::line(['due' => 11, 'calls' => 22, 'ok' => 33, 'failed' => 44, 'skipped' => 55, 'left' => 66, 'error' => null]);

        $this->assertSame(['due' => 11, 'calls' => 22, 'ok' => 33, 'failed' => 44, 'skipped' => 55, 'left' => 66], MirrorResyncSummary::parse($line));
    }

    public function testTheErrorIsReadBackAfterTheCounts(): void
    {
        $fields = MirrorResyncSummary::parse('due 1, calls 0, ok 0, failed 0, skipped 0, left 1; error: no webhook');

        $this->assertSame('no webhook', $fields['error']);
        $this->assertSame(1, $fields['left']);
        $this->assertSame('error', array_key_last($fields));
    }

    public function testATextThatIsNotASummaryIsKeptAsAMessage(): void
    {
        $this->assertSame(['message' => 'SQLSTATE boom'], MirrorResyncSummary::parse(" SQLSTATE boom\n"));
        $this->assertSame(['message' => 'x due 1, calls 0, ok 0, failed 0, skipped 0, left 1'], MirrorResyncSummary::parse('x due 1, calls 0, ok 0, failed 0, skipped 0, left 1'));
        $this->assertSame(['message' => 'due 1, calls 0, ok 0, failed 0, skipped 0, left 1 extra'], MirrorResyncSummary::parse('due 1, calls 0, ok 0, failed 0, skipped 0, left 1 extra'));
    }

    public function testNoSummaryGivesNoFields(): void
    {
        $this->assertSame([], MirrorResyncSummary::parse(null));
        $this->assertSame([], MirrorResyncSummary::parse('  '));
    }
}
