<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Twig;

use MauticPlugin\N8nDispatchBundle\Service\DispatchListReader;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The facts of one dispatch for the details table of a card (Resources/views/SubscribedEvents/Timeline/_dispatch_details.html.twig):
 * the contact's Timeline only has the log id, and reads the same row the details modal of the Dispatches screen shows, so both cards
 * say the same.
 */
class DispatchExtension extends AbstractExtension
{
    public function __construct(private readonly DispatchListReader $dispatches)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('n8ndispatch_dispatch', [$this, 'dispatchOf'])];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function dispatchOf(int $logId): ?array
    {
        return $this->dispatches->find($logId);
    }
}
