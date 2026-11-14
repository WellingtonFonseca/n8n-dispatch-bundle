<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service;

use MauticPlugin\N8nDispatchBundle\Service\StatusPollSummary;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('area:status-poll')]
#[Group('risk:DADOS')]
#[Group('risk:FLUXO')]
class StatusPollSummaryTest extends TestCase
{
    public function testTheLineCarriesEveryCountInItsOwnPlace(): void
    {
        $line = StatusPollSummary::line('hsm', ['requested' => 11, 'changed' => 22, 'unchanged' => 33, 'unknown' => 44, 'invalid' => 55, 'calls' => 66]);

        $this->assertSame('hsm: asked 11, changed 22, unchanged 33, unknown 44, invalid 55, 66 calls', $line);
    }

    public function testAWrittenLineIsReadBackWithTheSameCounts(): void
    {
        $line = StatusPollSummary::line('email', ['requested' => 1, 'changed' => 2, 'unchanged' => 3, 'unknown' => 4, 'invalid' => 5, 'calls' => 6]);

        $this->assertSame(
            ['email' => ['asked' => 1, 'changed' => 2, 'unchanged' => 3, 'unknown' => 4, 'invalid' => 5, 'calls' => 6]],
            StatusPollSummary::parse($line)
        );
    }

    public function testAnErrorLineIsReadBackAsTheChannelError(): void
    {
        $this->assertSame(['sms' => ['error' => 'webhook returned HTTP 500.']], StatusPollSummary::parse(StatusPollSummary::errorLine('sms', 'webhook returned HTTP 500.')));
    }

    public function testOneObjectPerChannelInTheOrderOfTheLines(): void
    {
        $fields = StatusPollSummary::parse("email: asked 1, changed 1, unchanged 0, unknown 0, invalid 0, 1 calls\nsms: asked 0, changed 0, unchanged 0, unknown 0, invalid 0, 0 calls");

        $this->assertSame(['email', 'sms'], array_keys($fields));
        $this->assertSame(1, $fields['email']['calls']);
    }

    public function testALineThatIsNotAChannelIsKeptAsAMessage(): void
    {
        $this->assertSame(['message' => 'boom'], StatusPollSummary::parse('boom'));
    }

    public function testNoSummaryGivesNoFields(): void
    {
        $this->assertSame([], StatusPollSummary::parse(null));
        $this->assertSame([], StatusPollSummary::parse(''));
    }

    public function testLinesAreTrimmedBlankOnesSkippedAndTheMessageKeepsTheLineBreaks(): void
    {
        $fields = StatusPollSummary::parse("  first problem  \n\n   \n  email: asked 1, changed 0, unchanged 1, unknown 0, invalid 0, 1 call  \nsecond problem");

        $this->assertSame("first problem\nsecond problem", $fields['message']);
        $this->assertSame(1, $fields['email']['calls'], 'the line after a blank one is still read');
    }

    public function testALineMustBeWholeToCountAsChannelCountsOrError(): void
    {
        $fields = StatusPollSummary::parse("x email: asked 1, changed 0, unchanged 0, unknown 0, invalid 0, 1 calls\nemail: asked 1, changed 0, unchanged 0, unknown 0, invalid 0, 1 calls extra\nx sms: broken\nsms: broken");

        $this->assertSame(['error' => 'broken'], $fields['sms']);
        $this->assertSame(['error' => 'asked 1, changed 0, unchanged 0, unknown 0, invalid 0, 1 calls extra'], $fields['email'], 'not a counts line: kept as the channel error text');
        $this->assertSame("x email: asked 1, changed 0, unchanged 0, unknown 0, invalid 0, 1 calls\nx sms: broken", $fields['message']);
    }
}
