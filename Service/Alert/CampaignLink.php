<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service\Alert;

use Mautic\CampaignBundle\Entity\Campaign;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * How an alert names a campaign: the id, the name and the absolute link to its page.
 */
class CampaignLink
{
    public function __construct(private UrlGeneratorInterface $router)
    {
    }

    public function url(int $campaignId): string
    {
        return $this->router->generate('mautic_campaign_action', ['objectAction' => 'view', 'objectId' => $campaignId], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /**
     * @return array{id: int, name: string|null, url: string}
     */
    public function reference(Campaign $campaign): array
    {
        $id = (int) $campaign->getId();

        return ['id' => $id, 'name' => $campaign->getName(), 'url' => $this->url($id)];
    }
}
