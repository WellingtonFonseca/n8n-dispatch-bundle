<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Twig;

use MauticPlugin\N8nDispatchBundle\Service\DispatchListReader;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The facts of one dispatch for the details table of a card (Resources/views/SubscribedEvents/Timeline/_dispatch_details.html.twig):
 * the contact's Timeline only has the log id, and reads the same row the details modal of the Dispatches screen shows, so both cards
 * say the same. The Timeline subscriber reads every card of the page in one query (preload); a card whose log was not preloaded reads its own.
 */
class DispatchExtension extends AbstractExtension
{
    /** @var array<int, array<string, mixed>|null> log id => dispatch (null: not one of ours), read once for the page */
    private array $preloaded = [];

    public function __construct(private readonly DispatchListReader $dispatches)
    {
    }

    /**
     * @param list<int> $logIds
     */
    public function preload(array $logIds): void
    {
        $found           = $this->dispatches->findMany($logIds);
        $this->preloaded = [];
        foreach ($logIds as $id) {
            $this->preloaded[$id] = $found[$id] ?? null;
        }
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
        return array_key_exists($logId, $this->preloaded) ? $this->preloaded[$logId] : $this->dispatches->find($logId);
    }
}
