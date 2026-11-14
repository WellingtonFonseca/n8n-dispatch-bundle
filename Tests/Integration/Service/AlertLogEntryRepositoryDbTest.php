<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Integration\Service;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\N8nDispatchBundle\Entity\AlertLogEntry;
use MauticPlugin\N8nDispatchBundle\Entity\AlertLogEntryRepository;
use MauticPlugin\N8nDispatchBundle\Tests\Integration\RollbackDatabaseTestCase;
use PHPUnit\Framework\Attributes\Group;

require_once __DIR__.'/../RollbackDatabaseTestCase.php';

/**
 * The SQL of AlertLogEntryRepository against the stack's real MySQL, in a transaction rolled back at the end. The test
 * reads only the rows it wrote, found by a unique alert name.
 */
#[Group('area:alerts')]
#[Group('risk:DADOS')]
class AlertLogEntryRepositoryDbTest extends RollbackDatabaseTestCase
{
    private AlertLogEntryRepository $repository;
    private string $unique;

    protected function setUp(): void
    {
        parent::setUp();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($this->db);
        $this->repository = new class($em) extends AlertLogEntryRepository {
            public function __construct(private EntityManagerInterface $manager)
            {
            }

            protected function getEntityManager(): EntityManagerInterface
            {
                return $this->manager;
            }

            public function getTableName(): string
            {
                return AlertLogEntry::TABLE_NAME;
            }
        };
        $this->unique = 'zz-test.'.microtime(true).'.'.random_int(1000, 9999);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mine(): array
    {
        return array_values(array_filter($this->repository->latest(1000), fn (array $row): bool => $this->unique === $row['alert']));
    }

    public function testARecordedAlertIsReadBackWithItsOutcomeReasonAndSubject(): void
    {
        $this->repository->record(new \DateTimeImmutable('2026-03-10 12:00:00', new \DateTimeZone('America/Sao_Paulo')), $this->unique, AlertLogEntry::OUTCOME_NOT_SENT, AlertLogEntry::REASON_REFUSED, 'HTTP 500', 'Black Friday');

        $this->assertSame([[
            'created_at' => '2026-03-10 15:00:00', // stored in UTC
            'alert'      => $this->unique,
            'outcome'    => 'not_sent',
            'reason'     => 'refused',
            'detail'     => 'HTTP 500',
            'subject'    => 'Black Friday',
        ]], $this->mine());
    }

    public function testASentAlertKeepsReasonDetailAndSubjectEmpty(): void
    {
        $this->repository->record(new \DateTimeImmutable(), $this->unique, AlertLogEntry::OUTCOME_SENT, null, null, null);

        $row = $this->mine()[0];
        $this->assertSame('sent', $row['outcome']);
        $this->assertNull($row['reason']);
        $this->assertNull($row['detail']);
        $this->assertNull($row['subject']);
    }

    public function testTheLatestComesFirstAndTheLimitCutsTheOldest(): void
    {
        foreach (['one', 'two', 'three'] as $subject) {
            $this->repository->record(new \DateTimeImmutable(), $this->unique, AlertLogEntry::OUTCOME_SENT, null, null, $subject);
        }

        $this->assertSame(['three', 'two'], array_map(fn (array $row) => $row['subject'], array_slice(array_filter($this->repository->latest(1000), fn (array $row): bool => $this->unique === $row['alert']), 0, 2)));
        $this->assertCount(2, $this->repository->latest(2));
        $this->assertCount(1, $this->repository->latest(0), 'a limit under 1 still returns one row');
    }

    public function testARowOlderThanTheKeepPeriodIsDroppedOnTheNextWrite(): void
    {
        $now = new \DateTimeImmutable('2026-03-10 12:00:00', new \DateTimeZone('UTC'));
        $this->repository->record($now->modify('-'.(AlertLogEntryRepository::KEEP_DAYS + 1).' days'), $this->unique, AlertLogEntry::OUTCOME_SENT, null, null, 'old');
        $this->repository->record($now->modify('-'.(AlertLogEntryRepository::KEEP_DAYS - 1).' days'), $this->unique, AlertLogEntry::OUTCOME_SENT, null, null, 'recent');
        $this->assertCount(2, $this->mine(), 'the cut is relative to the date of each write, so nothing is dropped yet');

        $this->repository->record($now, $this->unique, AlertLogEntry::OUTCOME_SENT, null, null, 'new');

        $this->assertEqualsCanonicalizing(['recent', 'new'], array_map(fn (array $row) => $row['subject'], $this->mine()));
    }

    public function testALongDetailAndSubjectAreCutToTheColumns(): void
    {
        $this->repository->record(new \DateTimeImmutable(), $this->unique, AlertLogEntry::OUTCOME_NOT_SENT, AlertLogEntry::REASON_ERROR, str_repeat('d', 2000), str_repeat('s', 500));

        $row = $this->mine()[0];
        $this->assertSame(1000, strlen($row['detail']));
        $this->assertSame(191, strlen($row['subject']));
    }
}
