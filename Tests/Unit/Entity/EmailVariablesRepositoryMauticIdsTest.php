<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Entity;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\QueryBuilder;
use MauticPlugin\N8nDispatchBundle\Entity\EmailVariablesRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The single query behind the "Enviar por" column: only the ids stored as provider "mautic".
 */
#[Group('area:email')]
#[Group('risk:DADOS')]
class EmailVariablesRepositoryMauticIdsTest extends TestCase
{
    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function repository(array $rows, ?QueryBuilder &$builder = null): EmailVariablesRepository
    {
        $query = $this->createMock(AbstractQuery::class);
        $query->expects($this->once())->method('getScalarResult')->willReturn($rows);

        $builder = $this->createMock(QueryBuilder::class);
        $builder->method('select')->willReturnSelf();
        $builder->method('where')->willReturnSelf();
        $builder->method('setParameter')->willReturnSelf();
        $builder->method('getQuery')->willReturn($query);

        $repository = $this->createPartialMock(EmailVariablesRepository::class, ['createQueryBuilder']);
        $repository->expects($this->once())->method('createQueryBuilder')->willReturn($builder);

        return $repository;
    }

    public function testReturnsTheIdsAsASetKeyedByIntegerId(): void
    {
        $this->assertSame([3 => true, 8 => true], $this->repository([['emailId' => '3'], ['emailId' => 8]])->getMauticEmailIds());
    }

    public function testNoMauticRowsGivesAnEmptySet(): void
    {
        $this->assertSame([], $this->repository([])->getMauticEmailIds());
    }

    public function testFiltersByTheMauticProvider(): void
    {
        $repository = $this->repository([], $builder);
        $builder->expects($this->once())->method('setParameter')->with('provider', 'mautic')->willReturnSelf();

        $repository->getMauticEmailIds();
    }
}
