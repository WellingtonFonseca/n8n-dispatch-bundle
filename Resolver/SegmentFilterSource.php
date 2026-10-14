<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Resolver;

use Mautic\LeadBundle\Entity\LeadList;
use Mautic\LeadBundle\Segment\ContactSegmentFilterFactory;

/**
 * The segment's filters, as Mautic itself reads them. A thin wrapper because Mautic 7 declares
 * ContactSegmentFilterFactory final, which PHPUnit cannot mock: the resolver (and its tests) depend on this class instead.
 */
class SegmentFilterSource
{
    public function __construct(private ContactSegmentFilterFactory $factory)
    {
    }

    /**
     * @return iterable<mixed>
     */
    public function filtersFor(LeadList $segment): iterable
    {
        return $this->factory->getSegmentFilters($segment);
    }
}
