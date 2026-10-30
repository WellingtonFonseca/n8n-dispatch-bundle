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

        $this->assertSame(['n8ndispatch_is_direct', 'n8ndispatch_mautic_outcome'], $names);
    }

    public function testADispatchOfMauticIsDirectAndAnythingElseIsNot(): void
    {
        $this->assertTrue($this->extension->isDirect(['n8ndispatch' => ['mode' => 'mautic']]));
        $this->assertFalse($this->extension->isDirect(['n8ndispatch' => ['status' => 'production']]));
        $this->assertFalse($this->extension->isDirect('not an array'));
        $this->assertFalse($this->extension->isDirect(null));
    }

    public function testTheOutcomeIsReadForTheContactTheCardKnows(): void
    {
        $metadata = ['n8ndispatch' => ['mode' => 'mautic', 'statId' => 4]];
        $this->outcome->expects($this->never())->method('leadOfLog');
        $this->outcome->expects($this->once())->method('detail')->with(27, $metadata)->willReturn(['state' => 'sent']);

        $this->assertSame(['state' => 'sent'], $this->extension->outcome($metadata, '27', 90));
    }

    public function testTheContactIsFoundByTheLogWhenTheCardHasNotGotIt(): void
    {
        $this->outcome->expects($this->once())->method('leadOfLog')->with(90)->willReturn(31);
        $this->outcome->expects($this->once())->method('detail')->with(31, [])->willReturn(['state' => 'queued']);

        $this->assertSame(['state' => 'queued'], $this->extension->outcome([], null, '90'));
    }

    public function testWithNeitherTheContactNorTheLogItAsksForNoContact(): void
    {
        $this->outcome->expects($this->never())->method('leadOfLog');
        $this->outcome->expects($this->once())->method('detail')->with(0, [])->willReturn(['state' => 'queued']);

        $this->extension->outcome('junk', 'x', 0);
    }
}
