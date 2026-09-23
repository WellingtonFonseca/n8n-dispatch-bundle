<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Resolver;

use Doctrine\Common\Collections\ArrayCollection;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadList;
use Mautic\LeadBundle\Segment\ContactSegmentFilter;
use Mautic\LeadBundle\Segment\ContactSegmentFilterFactory;
use Mautic\LeadBundle\Segment\ContactSegmentFilters;
use MauticPlugin\CustomObjectsBundle\Entity\CustomField;
use MauticPlugin\CustomObjectsBundle\Entity\CustomObject;
use MauticPlugin\CustomObjectsBundle\Model\CustomObjectModel;
use MauticPlugin\CustomObjectsBundle\Segment\Query\Filter\CustomFieldFilterQueryBuilder;
use MauticPlugin\CustomObjectsBundle\Segment\Query\Filter\CustomItemNameFilterQueryBuilder;
use MauticPlugin\N8nDispatchBundle\Resolver\CustomObjectVariableResolver;
use MauticPlugin\N8nDispatchBundle\Resolver\SegmentItemMatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers how CustomObjectVariableResolver combines a segment's Custom
 * Object conditions into the set of items whose value is used. The SQL
 * for each single condition is SegmentItemMatcher's job (it reuses the
 * Custom Objects plugin's own segment query builders) and is mocked here.
 *
 * Fixture: Custom Object #1 'disciplina' with fields #1 nome (text),
 * #2 posicao (int), #3 inicio (date). Another object's field is #9.
 */
class CustomObjectVariableResolverTest extends TestCase
{
    private const OBJECT_ID = 1;

    private ContactSegmentFilterFactory $segmentFilterFactory;

    private SegmentItemMatcher $itemMatcher;

    private CustomObjectModel $customObjectModel;

    private LoggerInterface $logger;

    private CustomObjectVariableResolver $resolver;

    private Lead $contact;

    protected function setUp(): void
    {
        $this->segmentFilterFactory = $this->createMock(ContactSegmentFilterFactory::class);
        $this->itemMatcher          = $this->createMock(SegmentItemMatcher::class);
        $this->customObjectModel    = $this->createMock(CustomObjectModel::class);
        $this->logger               = $this->createMock(LoggerInterface::class);

        $this->customObjectModel->method('fetchEntityByAlias')->with('disciplina')->willReturn($this->buildCustomObject());
        $this->itemMatcher->method('isContactInSegment')->willReturn(true);

        $this->resolver = new CustomObjectVariableResolver(
            $this->segmentFilterFactory,
            $this->itemMatcher,
            $this->customObjectModel,
            $this->logger,
        );

        $this->contact = new Lead();
        $this->contact->setId(1);
    }

    private function buildCustomObject(): CustomObject
    {
        $customObject = $this->createMock(CustomObject::class);
        $customObject->method('getId')->willReturn(self::OBJECT_ID);
        $customObject->method('getCustomFields')->willReturn(new ArrayCollection([
            $this->buildField(1, 'nome', 'text'),
            $this->buildField(2, 'posicao', 'int'),
            $this->buildField(3, 'inicio', 'date'),
        ]));

        return $customObject;
    }

    private function buildField(int $id, string $alias, string $type): CustomField
    {
        $field = $this->createMock(CustomField::class);
        $field->method('getId')->willReturn($id);
        $field->method('getAlias')->willReturn($alias);
        $field->method('getType')->willReturn($type);

        return $field;
    }

    private function fieldFilter(int $fieldId, string $operator = 'eq', string $glue = 'and'): ContactSegmentFilter
    {
        return $this->segmentFilter(CustomFieldFilterQueryBuilder::getServiceId(), $fieldId, $operator, $glue);
    }

    private function itemNameFilter(int $objectId, string $operator = 'eq', string $glue = 'and'): ContactSegmentFilter
    {
        return $this->segmentFilter(CustomItemNameFilterQueryBuilder::getServiceId(), $objectId, $operator, $glue);
    }

    private function contactFieldFilter(string $glue = 'and'): ContactSegmentFilter
    {
        return $this->segmentFilter('mautic.lead.query.builder.basic', 'email', 'eq', $glue);
    }

    private function segmentFilter(string $queryType, int|string $field, string $operator, string $glue): ContactSegmentFilter
    {
        $filter = $this->createMock(ContactSegmentFilter::class);
        $filter->method('getQueryType')->willReturn($queryType);
        $filter->method('getField')->willReturn($field);
        $filter->method('getOperator')->willReturn($operator);
        $filter->method('getGlue')->willReturn($glue);

        return $filter;
    }

    /**
     * @param ContactSegmentFilter[] $filters
     */
    private function campaignWithSegment(array $filters): Campaign
    {
        $segmentFilters = new ContactSegmentFilters();
        foreach ($filters as $filter) {
            $segmentFilters->addContactSegmentFilter($filter);
        }

        $list = new LeadList();
        $this->segmentFilterFactory->method('getSegmentFilters')->with($list)->willReturn($segmentFilters);

        $campaign = new Campaign();
        $campaign->addList($list);

        return $campaign;
    }

    /**
     * Maps each filter to the item ids its condition matches (the
     * "positive" set, before any negation).
     *
     * @param array<int, array{0: ContactSegmentFilter, 1: int[]}> $map
     */
    private function positiveMatches(array $map): void
    {
        $this->itemMatcher->method('findPositiveItemIds')->willReturnCallback(
            static function (ContactSegmentFilter $filter) use ($map): array {
                foreach ($map as [$mappedFilter, $ids]) {
                    if ($mappedFilter === $filter) {
                        return $ids;
                    }
                }

                return [];
            }
        );
    }

    /**
     * @param int[]    $expectedItemIds
     * @param string[] $values
     */
    private function expectValuesFetchedFor(array $expectedItemIds, string $targetFieldAlias, array $values): void
    {
        $this->itemMatcher->expects($this->once())
            ->method('fetchFieldValues')
            ->with(
                $this->callback(static function (array $ids) use ($expectedItemIds): bool {
                    sort($ids);

                    return $ids === $expectedItemIds;
                }),
                $this->callback(static fn (CustomField $field): bool => $field->getAlias() === $targetFieldAlias)
            )
            ->willReturn($values);
    }

    public function testCampaignWithoutSegmentsResolvesEmptyAndLogsWarning(): void
    {
        $this->logger->expects($this->once())->method('warning');
        $this->itemMatcher->expects($this->never())->method('fetchFieldValues');

        $this->assertSame('', $this->resolver->resolve($this->contact, new Campaign(), 'disciplina', 'nome'));
    }

    public function testSegmentWithoutConditionsOnThisObjectResolvesEmptyAndLogsWarning(): void
    {
        $campaign = $this->campaignWithSegment([$this->contactFieldFilter(), $this->fieldFilter(9)]);

        $this->logger->expects($this->once())->method('warning');
        $this->itemMatcher->expects($this->never())->method('fetchFieldValues');

        $this->assertSame('', $this->resolver->resolve($this->contact, $campaign, 'disciplina', 'nome'));
    }

    public function testSegmentTheContactIsNotInIsIgnored(): void
    {
        $posicao  = $this->fieldFilter(2);
        $campaign = $this->campaignWithSegment([$posicao]);

        $this->itemMatcher = $this->createMock(SegmentItemMatcher::class);
        $this->itemMatcher->method('isContactInSegment')->willReturn(false);
        $this->itemMatcher->expects($this->never())->method('findPositiveItemIds');
        $this->itemMatcher->expects($this->never())->method('fetchFieldValues');
        $resolver = new CustomObjectVariableResolver($this->segmentFilterFactory, $this->itemMatcher, $this->customObjectModel, $this->logger);

        $this->assertSame('', $resolver->resolve($this->contact, $campaign, 'disciplina', 'nome'));
    }

    public function testConditionsJoinedByAndMustAllMatchTheSameItem(): void
    {
        // posicao = 10 AND nome = 'xxxx': item 2 is the only one in both.
        $posicao  = $this->fieldFilter(2);
        $nome     = $this->fieldFilter(1);
        $campaign = $this->campaignWithSegment([$posicao, $nome]);

        $this->positiveMatches([[$posicao, [1, 2]], [$nome, [2, 3]]]);
        $this->expectValuesFetchedFor([2], 'nome', ['xxxx']);

        $this->assertSame('xxxx', $this->resolver->resolve($this->contact, $campaign, 'disciplina', 'nome'));
    }

    public function testAndConditionsWithNoCommonItemResolveEmpty(): void
    {
        $posicao  = $this->fieldFilter(2);
        $nome     = $this->fieldFilter(1);
        $campaign = $this->campaignWithSegment([$posicao, $nome]);

        $this->positiveMatches([[$posicao, [1]], [$nome, [3]]]);
        $this->itemMatcher->expects($this->never())->method('fetchFieldValues');

        $this->assertSame('', $this->resolver->resolve($this->contact, $campaign, 'disciplina', 'nome'));
    }

    public function testOrGroupsAddTheirItemsTogether(): void
    {
        // (posicao = 10 AND nome = 'a') OR (posicao = 20)
        $posicao10 = $this->fieldFilter(2);
        $nomeA     = $this->fieldFilter(1);
        $posicao20 = $this->fieldFilter(2, 'eq', 'or');
        $campaign  = $this->campaignWithSegment([$posicao10, $nomeA, $posicao20]);

        $this->positiveMatches([[$posicao10, [1, 2]], [$nomeA, [1]], [$posicao20, [4]]]);
        $this->expectValuesFetchedFor([1, 4], 'nome', ['a', 'd']);

        $this->assertSame('a<br>d', $this->resolver->resolve($this->contact, $campaign, 'disciplina', 'nome'));
    }

    public function testNegatedOperatorKeepsTheItemsThatDoNotMatchThePositiveCondition(): void
    {
        // posicao = 10 AND nome != 'x' — mirrors the segment's NOT EXISTS:
        // for 'neq' the matcher returns the items WITH nome = 'x'.
        $posicao  = $this->fieldFilter(2);
        $nomeNeq  = $this->fieldFilter(1, 'neq');
        $campaign = $this->campaignWithSegment([$posicao, $nomeNeq]);

        $this->positiveMatches([[$posicao, [1, 2, 3]], [$nomeNeq, [2]]]);
        $this->itemMatcher->method('findAllItemIds')->with($this->contact, self::OBJECT_ID)->willReturn([1, 2, 3, 4]);
        $this->expectValuesFetchedFor([1, 3], 'nome', ['a', 'c']);

        $this->assertSame('a<br>c', $this->resolver->resolve($this->contact, $campaign, 'disciplina', 'nome'));
    }

    public function testItemNameConditionOnThisObjectIsApplied(): void
    {
        $posicao  = $this->fieldFilter(2);
        $itemName = $this->itemNameFilter(self::OBJECT_ID);
        $campaign = $this->campaignWithSegment([$posicao, $itemName]);

        $this->positiveMatches([[$posicao, [1, 2]], [$itemName, [2]]]);
        $this->expectValuesFetchedFor([2], 'nome', ['b']);

        $this->assertSame('b', $this->resolver->resolve($this->contact, $campaign, 'disciplina', 'nome'));
    }

    public function testConditionsOnOtherObjectsAndContactFieldsDoNotNarrowTheItems(): void
    {
        // email = ... AND otherObject.field = ... AND posicao = 10
        $email      = $this->contactFieldFilter();
        $otherField = $this->fieldFilter(9);
        $otherName  = $this->itemNameFilter(99);
        $posicao    = $this->fieldFilter(2);
        $campaign   = $this->campaignWithSegment([$email, $otherField, $otherName, $posicao]);

        $this->positiveMatches([[$posicao, [1, 2]]]);
        $this->expectValuesFetchedFor([1, 2], 'nome', ['a', 'b']);

        $this->assertSame('a<br>b', $this->resolver->resolve($this->contact, $campaign, 'disciplina', 'nome'));
    }

    public function testDateTargetFieldIsFormattedToBrazilianDate(): void
    {
        $posicao  = $this->fieldFilter(2);
        $campaign = $this->campaignWithSegment([$posicao]);

        $this->positiveMatches([[$posicao, [1]]]);
        $this->expectValuesFetchedFor([1], 'inicio', ['2026-09-01']);

        $this->assertSame('01/09/2026', $this->resolver->resolve($this->contact, $campaign, 'disciplina', 'inicio'));
    }

    public function testUnknownTargetFieldResolvesEmptyAndLogsWarning(): void
    {
        $posicao  = $this->fieldFilter(2);
        $campaign = $this->campaignWithSegment([$posicao]);

        $this->positiveMatches([[$posicao, [1]]]);
        $this->logger->expects($this->once())->method('warning');
        $this->itemMatcher->expects($this->never())->method('fetchFieldValues');

        $this->assertSame('', $this->resolver->resolve($this->contact, $campaign, 'disciplina', 'naoexiste'));
    }

    public function testSeveralItemsAreJoinedWithTheGivenSeparator(): void
    {
        $posicao  = $this->fieldFilter(2);
        $campaign = $this->campaignWithSegment([$posicao]);

        $this->positiveMatches([[$posicao, [1, 2]]]);
        $this->expectValuesFetchedFor([1, 2], 'nome', ['a', 'b']);

        $this->assertSame("a\nb", $this->resolver->resolve($this->contact, $campaign, 'disciplina', 'nome', "\n"));
    }
}
