<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Resolver;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\Expression\ExpressionBuilder;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadList;
use MauticPlugin\CustomObjectsBundle\Entity\CustomField;
use MauticPlugin\CustomObjectsBundle\Entity\CustomObject;
use MauticPlugin\CustomObjectsBundle\Model\CustomObjectModel;
use MauticPlugin\N8nDispatchBundle\Resolver\CustomObjectVariableResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CustomObjectVariableResolverTest extends TestCase
{
    private EntityManagerInterface $em;

    private CustomObjectModel $customObjectModel;

    private LoggerInterface $logger;

    private CustomObjectVariableResolver $resolver;

    protected function setUp(): void
    {
        $this->em                = $this->createMock(EntityManagerInterface::class);
        $this->customObjectModel = $this->createMock(CustomObjectModel::class);
        $this->logger             = $this->createMock(LoggerInterface::class);

        $this->resolver = new CustomObjectVariableResolver(
            $this->em,
            $this->customObjectModel,
            $this->logger,
        );
    }

    // ---- resolveValue() — relative/absolute date handling, via reflection ----
    // Same logic manually verified live during the `between` operator
    // investigation (see wiki/n8n-dispatch-plugin.md) — now automated.

    private function callResolveValue(string $type, mixed $rawValue): ?string
    {
        $method = new \ReflectionMethod(CustomObjectVariableResolver::class, 'resolveValue');
        $method->setAccessible(true);

        return $method->invoke($this->resolver, $type, $rawValue);
    }

    public function testResolveValueReturnsNullForNull(): void
    {
        $this->assertNull($this->callResolveValue('date', null));
    }

    public function testResolveValuePassesThroughAbsoluteDateUnchanged(): void
    {
        $this->assertSame('2026-09-20', $this->callResolveValue('date', '2026-09-20'));
    }

    public function testResolveValueResolvesRelativeDateForDateType(): void
    {
        $expected = (new \DateTime())->modify('+ 10 days')->format('Y-m-d');

        $this->assertSame($expected, $this->callResolveValue('date', '+ 10 days'));
    }

    public function testResolveValueResolvesRelativeDateWithTimeForDatetimeType(): void
    {
        $expected = (new \DateTime())->modify('+ 10 days')->format('Y-m-d H:i:s');

        $this->assertSame($expected, $this->callResolveValue('datetime', '+ 10 days'));
    }

    public function testResolveValueDoesNotTreatNonDateTypesAsDates(): void
    {
        // A text/int field's value must never go through DateTime::modify(),
        // even if it happens to look like a relative-date string.
        $this->assertSame('+ 10 days', $this->callResolveValue('text', '+ 10 days'));
        $this->assertSame('42', $this->callResolveValue('int', 42));
    }

    // ---- findSegmentCondition() early-return paths, via the public resolve() ----
    // None of these reach the DBAL layer, so no QueryBuilder mocking needed.

    public function testResolveReturnsEmptyWhenCampaignHasNoLists(): void
    {
        $this->logger->expects($this->once())->method('warning');

        $result = $this->resolver->resolve(new Lead(), new Campaign(), 'disciplines', 'discname');

        $this->assertSame('', $result);
    }

    public function testResolveSkipsFiltersNotOnCustomObjects(): void
    {
        $list = new LeadList();
        $list->setFilters([
            ['object' => 'lead', 'field' => 'email', 'operator' => '=', 'filter' => 'x@example.com'],
        ]);
        $campaign = new Campaign();
        $campaign->addList($list);

        $result = $this->resolver->resolve(new Lead(), $campaign, 'disciplines', 'discname');

        $this->assertSame('', $result);
    }

    public function testResolveSkipsWhenCustomFieldNotFound(): void
    {
        $list = new LeadList();
        $list->setFilters([
            ['object' => 'custom_object', 'field' => 'cmf_4', 'type' => 'date', 'operator' => 'lte', 'properties' => ['filter' => '+ 10 days']],
        ]);
        $campaign = new Campaign();
        $campaign->addList($list);

        $repository = $this->createMock(ObjectRepository::class);
        $repository->method('find')->with(4)->willReturn(null);
        $this->em->method('getRepository')->with(CustomField::class)->willReturn($repository);

        $result = $this->resolver->resolve(new Lead(), $campaign, 'disciplines', 'discname');

        $this->assertSame('', $result);
    }

    public function testResolveSkipsWhenCustomObjectAliasDoesNotMatch(): void
    {
        $list = new LeadList();
        $list->setFilters([
            ['object' => 'custom_object', 'field' => 'cmf_4', 'type' => 'date', 'operator' => 'lte', 'properties' => ['filter' => '+ 10 days']],
        ]);
        $campaign = new Campaign();
        $campaign->addList($list);

        $otherObject = new CustomObject();
        $otherObject->setAlias('courses');
        $field = new CustomField();
        $field->setType('date');
        $field->setCustomObject($otherObject);

        $repository = $this->createMock(ObjectRepository::class);
        $repository->method('find')->willReturn($field);
        $this->em->method('getRepository')->with(CustomField::class)->willReturn($repository);

        $result = $this->resolver->resolve(new Lead(), $campaign, 'disciplines', 'discname');

        $this->assertSame('', $result);
    }

    // ---- Full flow through the DBAL layer ----

    /**
     * Builds a Campaign whose one source Segment has a Custom Object filter
     * on the "disciplines" object's "discstart" field (id=4, type date),
     * and wires the EntityManager mock so findSegmentCondition() resolves
     * it — everything short of the actual query results, which each test
     * configures on the returned QueryBuilder mock.
     */
    private function setUpConditionAndQueryBuilder(string $operator, mixed $filterValue): QueryBuilder
    {
        $list = new LeadList();
        $list->setFilters([
            ['object' => 'custom_object', 'field' => 'cmf_4', 'type' => 'date', 'operator' => $operator, 'properties' => ['filter' => $filterValue]],
        ]);
        $campaign = new Campaign();
        $campaign->addList($list);

        $disciplines = new CustomObject();
        $disciplines->setAlias('disciplines');
        $field = new CustomField();
        $field->setId(4);
        $field->setType('date');
        $field->setCustomObject($disciplines);

        $repository = $this->createMock(ObjectRepository::class);
        $repository->method('find')->with(4)->willReturn($field);
        $this->em->method('getRepository')->with(CustomField::class)->willReturn($repository);

        $qb = $this->createMock(QueryBuilder::class);
        foreach (['select', 'from', 'innerJoin', 'where', 'andWhere', 'setParameter'] as $fluentMethod) {
            $qb->method($fluentMethod)->willReturnSelf();
        }
        $expr = $this->createMock(ExpressionBuilder::class);
        $expr->method('in')->willReturn('civ.custom_item_id IN (:itemIds)');
        $qb->method('expr')->willReturn($expr);

        $connection = $this->createMock(Connection::class);
        $connection->method('createQueryBuilder')->willReturn($qb);
        $this->em->method('getConnection')->willReturn($connection);

        $this->campaign = $campaign;

        return $qb;
    }

    private Campaign $campaign;

    public function testUnsupportedOperatorMatchesNothingAndLogsWarning(): void
    {
        // Value is a well-formed date string purely so resolveValue()'s
        // unconditional DateTime::modify() call (for the 'date'-typed field
        // this fixture uses) doesn't emit an unrelated parse warning — 'in'
        // never actually uses the resolved value, it always matches nothing.
        $qb = $this->setUpConditionAndQueryBuilder('in', '2026-01-01');

        $qb->expects($this->atLeastOnce())
            ->method('andWhere')
            ->with($this->logicalOr(
                $this->stringContains('civ.custom_field_id'),
                $this->stringContains('cix.contact_id'),
                $this->equalTo('1 = 0')
            ));

        $result   = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn([]);
        $qb->method('executeQuery')->willReturn($result);

        $this->logger->expects($this->atLeastOnce())->method('warning');

        $contact = new Lead();
        $contact->setId(1);

        $this->assertSame('', $this->resolver->resolve($contact, $this->campaign, 'disciplines', 'discname'));
    }

    public function testMatchingItemsAreJoinedWithBr(): void
    {
        $qb = $this->setUpConditionAndQueryBuilder('lte', '+ 10 days');

        $matchResult = $this->createMock(Result::class);
        $matchResult->method('fetchAllAssociative')->willReturn([
            ['custom_item_id' => 3],
            ['custom_item_id' => 4],
        ]);

        $valuesResult = $this->createMock(Result::class);
        $valuesResult->method('fetchAllAssociative')->willReturn([
            ['value' => 'disciplina 1'],
            ['value' => 'disciplina 2'],
        ]);

        $qb->method('executeQuery')->willReturnOnConsecutiveCalls($matchResult, $valuesResult);

        $targetField = new CustomField();
        $targetField->setId(5);
        $targetField->setAlias('discname');
        $targetField->setType('text');

        $disciplines = new CustomObject();
        $disciplines->setAlias('disciplines');
        $disciplines->addCustomField($targetField);

        $this->customObjectModel->method('fetchEntityByAlias')->with('disciplines')->willReturn($disciplines);

        $contact = new Lead();
        $contact->setId(1);

        $result = $this->resolver->resolve($contact, $this->campaign, 'disciplines', 'discname');

        $this->assertSame('disciplina 1<br>disciplina 2', $result);
    }

    public function testNoMatchingItemsResolvesToEmptyStringWithoutFetchingFieldValues(): void
    {
        $qb = $this->setUpConditionAndQueryBuilder('lte', '+ 10 days');

        $emptyResult = $this->createMock(Result::class);
        $emptyResult->method('fetchAllAssociative')->willReturn([]);
        $qb->method('executeQuery')->willReturn($emptyResult);

        $this->customObjectModel->expects($this->never())->method('fetchEntityByAlias');

        $contact = new Lead();
        $contact->setId(1);

        $this->assertSame('', $this->resolver->resolve($contact, $this->campaign, 'disciplines', 'discname'));
    }
}
