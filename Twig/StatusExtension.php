<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Twig;

use MauticPlugin\N8nDispatchBundle\Service\StatusTracker;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Gives the Timeline card (Resources/views/SubscribedEvents/Timeline/
 * _email_send.html.twig) the callback status of one campaign log:
 * {{ n8ndispatch_status(item.metadata) }}.
 */
class StatusExtension extends AbstractExtension
{
    public function __construct(private StatusTracker $tracker)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('n8ndispatch_status', [$this, 'status']),
        ];
    }

    /**
     * @param mixed $metadata the log's metadata, as the Timeline template sees it
     *
     * @return list<array<string, mixed>>
     */
    public function status(mixed $metadata): array
    {
        return is_array($metadata) ? $this->tracker->viewForMetadata($metadata) : [];
    }
}
