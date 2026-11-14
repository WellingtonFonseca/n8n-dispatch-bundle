<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service\Alert;

use Mautic\CampaignBundle\Entity\Campaign;
use MauticPlugin\N8nDispatchBundle\Service\Alert\CampaignLink;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[Group('area:alerts')]
#[Group('risk:DADOS')]
class CampaignLinkTest extends TestCase
{
    private function link(): CampaignLink
    {
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')
            ->with('mautic_campaign_action', ['objectAction' => 'view', 'objectId' => 7], UrlGeneratorInterface::ABSOLUTE_URL)
            ->willReturn('https://mautic.test/s/campaigns/view/7');

        return new CampaignLink($router);
    }

    public function testTheUrlIsTheAbsoluteViewPageOfTheCampaign(): void
    {
        $this->assertSame('https://mautic.test/s/campaigns/view/7', $this->link()->url(7));
    }

    public function testTheReferenceHasTheIdTheNameAndTheUrl(): void
    {
        $campaign = $this->createStub(Campaign::class);
        $campaign->method('getId')->willReturn(7);
        $campaign->method('getName')->willReturn('Boas-vindas');

        $this->assertSame(['id' => 7, 'name' => 'Boas-vindas', 'url' => 'https://mautic.test/s/campaigns/view/7'], $this->link()->reference($campaign));
    }
}
