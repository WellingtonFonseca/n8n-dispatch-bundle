<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Resolver;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\LeadBundle\Segment\ContactSegmentFilter;
use Mautic\LeadBundle\Segment\ContactSegmentFilterCrate;
use MauticPlugin\CustomObjectsBundle\Helper\QueryFilterHelper;
use MauticPlugin\CustomObjectsBundle\Provider\CustomFieldTypeProvider;
use MauticPlugin\N8nDispatchBundle\Resolver\SegmentItemMatcher;
use PHPUnit\Framework\TestCase;

/**
 * Only the pure part of SegmentItemMatcher is covered here: deciding which
 * criteria of a merged Custom Object filter belong to the resolved object.
 * The SQL (findMergedItemIds) is validated live against the real database,
 * like the rest of this class.
 *
 * Fixture: Custom Object #1 owns fields #1, #2 and #3; #9 is another object's field.
 */
class SegmentItemMatcherTest extends TestCase
{
    private const OBJECT_ID = 1;

    private const FIELD_IDS = [1, 2, 3];

    private SegmentItemMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new SegmentItemMatcher(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(QueryFilterHelper::class),
            $this->createMock(CustomFieldTypeProvider::class),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $criteria
     */
    private function mergedFilter(array $criteria): ContactSegmentFilter
    {
        $filter                            = $this->createMock(ContactSegmentFilter::class);
        $filter->contactSegmentFilterCrate = new ContactSegmentFilterCrate([
            'glue'            => 'and',
            'field'           => 'cmf_1',
            'object'          => 'custom_object',
            'type'            => 'date',
            'operator'        => 'custom_operator',
            'merged_property' => $criteria,
        ]);

        return $filter;
    }

    /**
     * @return array<string, mixed>
     */
    private function criterion(int $field, bool $isObjectName = false): array
    {
        return ['operator' => 'lt', 'filter_value' => '2026-10-01', 'field' => (string) $field, 'type' => 'date', 'cmo_filter' => $isObjectName];
    }

    /**
     * A multiselect keeps one row per selected option. The options of ONE item are
     * joined together, so they cannot be mistaken for several items.
     */
    public function testOptionRowsAreGroupedPerItemInTheGivenOrder(): void
    {
        $rows = [
            ['custom_item_id' => 3, 'value' => 'grad'],
            ['custom_item_id' => 3, 'value' => 'pos'],
            ['custom_item_id' => 5, 'value' => 'extensao'],
            ['custom_item_id' => 8, 'value' => 'pos'],
            ['custom_item_id' => 8, 'value' => 'extensao'],
        ];

        $this->assertSame(['grad, pos', 'extensao', 'pos, extensao'], SegmentItemMatcher::groupOptionValues($rows));
    }

    public function testNoOptionRowsGiveNoValues(): void
    {
        $this->assertSame([], SegmentItemMatcher::groupOptionValues([]));
    }

    public function testMergedFilterWithAFieldOfTheObjectConcernsIt(): void
    {
        $filter = $this->mergedFilter([$this->criterion(3), $this->criterion(2)]);

        $this->assertTrue($this->matcher->mergedFilterConcernsObject($filter, self::OBJECT_ID, self::FIELD_IDS));
    }

    public function testMergedFilterWithOnlyFieldsOfAnotherObjectDoesNotConcernIt(): void
    {
        $filter = $this->mergedFilter([$this->criterion(9)]);

        $this->assertFalse($this->matcher->mergedFilterConcernsObject($filter, self::OBJECT_ID, self::FIELD_IDS));
    }

    public function testMergedFilterWithAnItemNameCriterionOfTheObjectConcernsIt(): void
    {
        $filter = $this->mergedFilter([$this->criterion(self::OBJECT_ID, true)]);

        $this->assertTrue($this->matcher->mergedFilterConcernsObject($filter, self::OBJECT_ID, self::FIELD_IDS));
    }

    public function testItemNameCriterionIsMatchedByObjectIdNotByFieldId(): void
    {
        // Field id 1 is a field of the object, but with cmo_filter it means "object #1".
        $filter = $this->mergedFilter([$this->criterion(2, true)]);

        $this->assertFalse($this->matcher->mergedFilterConcernsObject($filter, self::OBJECT_ID, self::FIELD_IDS));
    }
}
