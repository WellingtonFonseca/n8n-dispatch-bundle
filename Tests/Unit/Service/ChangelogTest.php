<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service;

use MauticPlugin\N8nDispatchBundle\Service\Changelog;
use PHPUnit\Framework\TestCase;

class ChangelogTest extends TestCase
{
    public function testItReadsOneBlockPerDayInTheFileOrder(): void
    {
        $days = Changelog::parse("# Changelog\n\nintro\n\n## 2026-10-02\n- first\n- second\n\n## 2026-10-01\n- third\n");

        $this->assertSame([
            ['date' => '2026-10-02', 'items' => ['first', 'second']],
            ['date' => '2026-10-01', 'items' => ['third']],
        ], $days);
    }

    public function testAChangeBeforeTheFirstDateAndOtherLinesAreIgnored(): void
    {
        $days = Changelog::parse("- orphan\n## not a date\n- also orphan\n## 2026-10-02\ntext\n- kept  \n### 2026-10-01\n- under a h3\n");

        $this->assertSame([['date' => '2026-10-02', 'items' => ['kept', 'under a h3']]], $days);
    }

    public function testWindowsLineEndingsWork(): void
    {
        $this->assertSame([['date' => '2026-10-02', 'items' => ['a', 'b']]], Changelog::parse("## 2026-10-02\r\n- a\r\n- b\r\n"));
    }

    public function testAMissingFileGivesNoDays(): void
    {
        $this->assertSame([], Changelog::days('/nonexistent/CHANGELOG.md'));
    }

    public function testTheShippedFileFollowsTheFormat(): void
    {
        $days = Changelog::days();

        $this->assertNotEmpty($days);
        $this->assertSame('2026-09-15', end($days)['date'], 'goes back to the first day of the project');

        $dates = array_column($days, 'date');
        $sorted = $dates;
        rsort($sorted);
        $this->assertSame($sorted, $dates, 'newest first');
        $this->assertSame(array_unique($dates), $dates, 'one block per day');

        foreach ($days as $day) {
            $this->assertNotEmpty($day['items'], $day['date'].' has no change');
        }
    }
}
