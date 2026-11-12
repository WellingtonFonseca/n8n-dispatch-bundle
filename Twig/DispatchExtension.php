<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Twig;

use MauticPlugin\N8nDispatchBundle\Service\DispatchListReader;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The facts of one dispatch for the details table of a card (Resources/views/SubscribedEvents/Timeline/_dispatch_details.html.twig):
 * the contact's Timeline only has the log id, and reads the same row the details modal of the Dispatches screen shows, so both cards
 * say the same. The Timeline subscriber queues the dispatch cards of a page; the first card that renders reads every queued one in one
 * query. Lazy, because a timeline is also generated with no card drawn (the CSV export), and one request may build several contacts'
 * timelines before it renders (core's getAllEngagements). A card whose log was not queued reads its own.
 *
 * @phpstan-import-type DispatchRow from DispatchListReader
 */
class DispatchExtension extends AbstractExtension implements ResetInterface
{
    /** @var array<int, true> log ids of queued cards not read yet */
    private array $queued = [];

    /** @var array<int, DispatchRow|null> log id => dispatch (null: not one of ours) */
    private array $rowsByLogId = [];

    public function __construct(private readonly DispatchListReader $dispatches)
    {
    }

    /**
     * @param list<int> $logIds
     */
    public function queue(array $logIds): void
    {
        foreach ($logIds as $id) {
            if (!array_key_exists($id, $this->rowsByLogId)) {
                $this->queued[$id] = true;
            }
        }
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('n8ndispatch_dispatch', [$this, 'dispatchOf'])];
    }

    /**
     * @return DispatchRow|null
     */
    public function dispatchOf(int $logId): ?array
    {
        if (isset($this->queued[$logId])) {
            $this->readQueued();
        }

        if (!array_key_exists($logId, $this->rowsByLogId)) {
            $this->rowsByLogId[$logId] = $this->dispatches->find($logId);
        }

        return $this->rowsByLogId[$logId];
    }

    // a long-running process (worker, test kernel) must not show a row read for an earlier request
    public function reset(): void
    {
        $this->queued      = [];
        $this->rowsByLogId = [];
    }

    private function readQueued(): void
    {
        $ids               = array_keys($this->queued);
        $this->rowsByLogId = $this->dispatches->findMany($ids) + array_fill_keys($ids, null) + $this->rowsByLogId;
        $this->queued      = [];
    }
}
