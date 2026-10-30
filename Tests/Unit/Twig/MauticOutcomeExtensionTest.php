<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Twig;

use MauticPlugin\N8nDispatchBundle\Service\MauticEmailOutcome;
use MauticPlugin\N8nDispatchBundle\Twig\MauticOutcomeExtension;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class MauticOutcomeExtensionTest extends TestCase
{
    private MauticEmailOutcome&MockObject $outcome;
    private MauticOutcomeExtension $extension;

    protected function setUp(): void
    {
        $this->outcome   = $this->createMock(MauticEmailOutcome::class);
        $this->extension = new MauticOutcomeExtension($this->outcome);
    }

    public function testItOffersTheTwoFunctionsOfTheCard(): void
    {
        $names = array_map(static fn (\Twig\TwigFunction $f): string => $f->getName(), $this->extension->getFunctions());

        $this->assertSame(['n8ndispatch_is_direct', 'n8ndispatch_mautic_outcome', 'n8ndispatch_mautic_badge'], $names);
    }

    public function testADispatchOfMauticIsDirectAndAnythingElseIsNot(): void
    {
        $this->assertTrue($this->extension->isDirect(['n8ndispatch' => ['mode' => 'mautic']]));
        $this->assertFalse($this->extension->isDirect(['n8ndispatch' => ['status' => 'production']]));
        $this->assertFalse($this->extension->isDirect('not an array'));
        $this->assertFalse($this->extension->isDirect(null));
    }

    public function testTheContactTheStepAndTheMomentComeFromTheLog(): void
    {
        $metadata = ['n8ndispatch' => ['mode' => 'mautic', 'statId' => null]];
        $this->outcome->expects($this->once())->method('factsOfLog')->with(90)->willReturn(['lead' => 31, 'event' => 8, 'since' => '2026-05-01 10:00:00']);
        $this->outcome->expects($this->once())->method('detail')->with(31, $metadata, 8, '2026-05-01 10:00:00')->willReturn(['state' => 'queued']);

        $this->assertSame(['state' => 'queued'], $this->extension->outcome($metadata, null, '90'));
    }

    public function testTheContactTheCardKnowsIsTheFallbackWhenTheLogIsGone(): void
    {
        $this->outcome->method('factsOfLog')->willReturn(['lead' => 0, 'event' => 0, 'since' => null]);
        $this->outcome->expects($this->once())->method('detail')->with(27, [], 0, null)->willReturn(['state' => 'queued']);

        $this->extension->outcome([], '27', 90);
    }

    public function testWithoutALogTheContactTheCardKnowsIsUsedAndNothingIsLookedUp(): void
    {
        $metadata = ['n8ndispatch' => ['mode' => 'mautic', 'statId' => 4]];
        $this->outcome->expects($this->never())->method('factsOfLog');
        $this->outcome->expects($this->once())->method('detail')->with(27, $metadata)->willReturn(['state' => 'sent']);

        $this->assertSame(['state' => 'sent'], $this->extension->outcome($metadata, '27', null));
    }

    public function testWithNeitherTheContactNorTheLogItAsksForNoContact(): void
    {
        $this->outcome->expects($this->never())->method('factsOfLog');
        $this->outcome->expects($this->once())->method('detail')->with(0, [])->willReturn(['state' => 'queued']);

        $this->extension->outcome('junk', 'x', 0);
    }
}
