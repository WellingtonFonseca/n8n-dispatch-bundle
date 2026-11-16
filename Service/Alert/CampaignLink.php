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
        return $this->named((int) $campaign->getId(), $campaign->getName());
    }

    /**
     * The same reference for a caller that has only the id and the name (a SQL row), not the entity.
     *
     * @return array{id: int, name: string|null, url: string}
     */
    public function named(int $campaignId, ?string $name): array
    {
        return ['id' => $campaignId, 'name' => $name, 'url' => $this->url($campaignId)];
    }
}
