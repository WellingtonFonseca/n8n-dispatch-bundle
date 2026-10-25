<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Resolver;

use Doctrine\Common\Collections\ArrayCollection;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\CustomObjectsBundle\Entity\CustomField;
use MauticPlugin\CustomObjectsBundle\Entity\CustomObject;
use MauticPlugin\CustomObjectsBundle\Model\CustomObjectModel;
use MauticPlugin\CustomObjectsBundle\Segment\SegmentItemMatcher;
use MauticPlugin\CustomObjectsBundle\Segment\SegmentItemResolver;
use MauticPlugin\N8nDispatchBundle\Resolver\CustomObjectVariableResolver;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers how CustomObjectVariableResolver turns the items the segments select into the text of a variable: the target field
 * lookup, the formatting of dates and decimals, the separator. WHICH items the segments select is the Custom Objects plugin's
 * SegmentItemResolver (tested there) and is mocked here.
 *
 * Fixture: Custom Object #1 'disciplina' with fields #1 nome (text), #2 posicao (int), #3 inicio (date), #4 valor (decimal).
 */
#[AllowMockObjectsWithoutExpectations]
class CustomObjectVariableResolverTest extends TestCase
{
    private const OBJECT_ID = 1;

    private SegmentItemResolver $itemResolver;

    private SegmentItemMatcher $itemMatcher;

    private CustomObjectModel $customObjectModel;

    private LoggerInterface $logger;

    private CustomObjectVariableResolver $resolver;

    private Lead $contact;

    protected function setUp(): void
    {
        $this->itemResolver      = $this->createMock(SegmentItemResolver::class);
        $this->itemMatcher       = $this->createMock(SegmentItemMatcher::class);
        $this->customObjectModel = $this->createMock(CustomObjectModel::class);
        $this->logger            = $this->createMock(LoggerInterface::class);

        $this->customObjectModel->method('fetchEntityByAlias')->with('disciplina')->willReturn($this->buildCustomObject());

        $this->resolver = new CustomObjectVariableResolver(
            $this->itemResolver,
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
            $this->buildField(4, 'valor', 'decimal'),
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

    /**
     * What SegmentItemResolver answers: the items the segments select (null: no segment condition on the object).
     *
     * @param int[]|null $itemIds
     */
    private function itemsSelected(?array $itemIds): void
    {
        $this->itemResolver->method('resolveItemIds')->willReturn($itemIds);
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

    public function testTheCampaignSegmentsAreAskedWhichItemsCount(): void
    {
        $campaign = new Campaign();

        $this->itemResolver->expects($this->once())->method('resolveItemIds')
            ->with($this->contact, $campaign->getLists(), $this->isInstanceOf(CustomObject::class))
            ->willReturn([2]);
        $this->expectValuesFetchedFor([2], 'nome', ['xxxx']);

        $this->assertSame('xxxx', $this->resolver->resolve($this->contact, $campaign, 'disciplina', 'nome'));
    }

    public function testNoSegmentConditionOnThisObjectResolvesEmptyAndLogsWarning(): void
    {
        $this->itemsSelected(null);
        $this->logger->expects($this->once())->method('warning');
        $this->itemMatcher->expects($this->never())->method('fetchFieldValues');

        $this->assertSame('', $this->resolver->resolve($this->contact, new Campaign(), 'disciplina', 'nome'));
    }

    public function testNoItemSatisfyingTheConditionsResolvesEmptyWithoutWarning(): void
    {
        $this->itemsSelected([]);
        $this->logger->expects($this->never())->method('warning');
        $this->itemMatcher->expects($this->never())->method('fetchFieldValues');

        $this->assertSame('', $this->resolver->resolve($this->contact, new Campaign(), 'disciplina', 'nome'));
    }

    public function testDateTargetFieldIsFormattedToBrazilianDate(): void
    {
        $campaign = new Campaign();
        $this->itemsSelected([1]);
        $this->expectValuesFetchedFor([1], 'inicio', ['2026-09-01']);

        $this->assertSame('01/09/2026', $this->resolver->resolve($this->contact, $campaign, 'disciplina', 'inicio'));
    }

    public function testDecimalTargetFieldIsFormattedWithTwoPlacesMinimum(): void
    {
        $campaign = new Campaign();
        $this->itemsSelected([1, 2]);
        $this->expectValuesFetchedFor([1, 2], 'valor', ['1.100000', '1.000100']);

        $this->assertSame('1,10<br>1,0001', $this->resolver->resolve($this->contact, $campaign, 'disciplina', 'valor'));
    }

    public function testTargetFieldsFollowTheGivenLanguage(): void
    {
        $campaign = new Campaign();
        $this->itemsSelected([1]);
        $this->expectValuesFetchedFor([1], 'valor', ['1.100000']);
        $this->assertSame('1.10', $this->resolver->resolve($this->contact, $campaign, 'disciplina', 'valor', '<br>', 'en'));
    }

    public function testDateTargetFieldFollowsTheGivenLanguage(): void
    {
        $campaign = new Campaign();
        $this->itemsSelected([1]);
        $this->expectValuesFetchedFor([1], 'inicio', ['2026-09-01']);
        $this->assertSame('09/01/2026', $this->resolver->resolve($this->contact, $campaign, 'disciplina', 'inicio', '<br>', 'en'));
    }

    public function testUnknownTargetFieldResolvesEmptyAndLogsWarning(): void
    {
        $campaign = new Campaign();
        $this->itemsSelected([1]);
        $this->logger->expects($this->once())->method('warning');
        $this->itemMatcher->expects($this->never())->method('fetchFieldValues');

        $this->assertSame('', $this->resolver->resolve($this->contact, $campaign, 'disciplina', 'naoexiste'));
    }

    /**
     * An unfilled field is stored as '' or NULL: it must not leave an empty entry
     * (and a stray separator) between or before the other values.
     */
    public function testEmptyValuesAreSkippedWhenJoining(): void
    {
        $campaign = new Campaign();
        $this->itemsSelected([1, 2, 3]);
        $this->expectValuesFetchedFor([1, 2, 3], 'nome', ['', 'a', '']);

        $this->assertSame('a', $this->resolver->resolve($this->contact, $campaign, 'disciplina', 'nome'));
    }

    public function testSeveralItemsAreJoinedWithTheGivenSeparator(): void
    {
        $campaign = new Campaign();
        $this->itemsSelected([1, 2]);
        $this->expectValuesFetchedFor([1, 2], 'nome', ['a', 'b']);

        $this->assertSame("a\nb", $this->resolver->resolve($this->contact, $campaign, 'disciplina', 'nome', "\n"));
    }
}
