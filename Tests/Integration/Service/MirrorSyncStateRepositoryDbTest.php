<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Integration\Service;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\N8nDispatchBundle\Entity\MirrorSyncState;
use MauticPlugin\N8nDispatchBundle\Entity\MirrorSyncStateRepository;
use MauticPlugin\N8nDispatchBundle\Entity\PollRun;
use MauticPlugin\N8nDispatchBundle\Entity\PollRunRepository;
use MauticPlugin\N8nDispatchBundle\Tests\Integration\RollbackDatabaseTestCase;
use PHPUnit\Framework\Attributes\Group;

require_once __DIR__.'/../RollbackDatabaseTestCase.php';

/**
 * The SQL of the Mirror sync state and of the run claim per kind, against the stack's real MySQL, in a transaction
 * rolled back at the end. Every row is the test's own (a unique Email name).
 */
#[Group('area:mirror')]
#[Group('risk:DADOS')]
class MirrorSyncStateRepositoryDbTest extends RollbackDatabaseTestCase
{
    private MirrorSyncStateRepository $states;

    private PollRunRepository $runs;

    /** The lock of the status poll and the Mirror resync: a run of one blocks the other. */
    private const SHARED_LANE = [PollRun::KIND_STATUS_POLL, PollRun::KIND_MIRROR_RESYNC];

    /** The failure sweep has a lane of its own. */
    private const SWEEP_LANE = [PollRun::KIND_FAILURE_SWEEP];

    private int $emailId;

    protected function setUp(): void
    {
        parent::setUp();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($this->db);
        $this->states = new class($em) extends MirrorSyncStateRepository {
            public function __construct(private EntityManagerInterface $manager)
            {
            }

            protected function getEntityManager(): EntityManagerInterface
            {
                return $this->manager;
            }

            public function getTableName(): string
            {
                return MirrorSyncState::TABLE_NAME;
            }
        };
        $this->runs = new class($em) extends PollRunRepository {
            public function __construct(private EntityManagerInterface $manager)
            {
            }

            protected function getEntityManager(): EntityManagerInterface
            {
                return $this->manager;
            }

            public function getTableName(): string
            {
                return PollRun::TABLE_NAME;
            }
        };
        // Runs of other tests or of the stack must not decide this test's claims.
        $this->db->executeStatement('DELETE FROM '.PollRun::TABLE_NAME);
        $this->emailId = $this->email('zz-mirror.'.microtime(true).'.'.random_int(1000, 9999));
    }

    private function email(string $name, string $html = '<p>x</p>'): int
    {
        return $this->insert('emails', [
            'name' => $name, 'subject' => 'Subj', 'custom_html' => $html, 'preheader_text' => 'Pre', 'from_name' => 'From', 'from_address' => 'from@x.test',
            'is_published' => 1, 'read_count' => 0, 'sent_count' => 0, 'variant_sent_count' => 0, 'variant_read_count' => 0, 'revision' => 1, 'lang' => 'en', 'headers' => '[]',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function stateRow(): array
    {
        return $this->db->fetchAssociative('SELECT * FROM '.MirrorSyncState::TABLE_NAME.' WHERE email_id = ?', [$this->emailId]) ?: [];
    }

    public function testFailuresCountUpAndASuccessResetsThemAndKeepsTheSyncDate(): void
    {
        $at = fn (string $time): \DateTimeImmutable => new \DateTimeImmutable('2026-11-13 '.$time, new \DateTimeZone('America/Sao_Paulo'));

        $this->states->record($this->emailId, MirrorSyncState::STATUS_FAILED, 'h1', 500, 'HTTP 500', MirrorSyncState::SOURCE_SAVE, $at('09:00:00'));
        $this->states->record($this->emailId, MirrorSyncState::STATUS_FAILED, 'h1', null, 'refused', MirrorSyncState::SOURCE_JOB, $at('10:00:00'));
        $row = $this->stateRow();
        $this->assertSame(['failed', '2', null, 'refused', 'job', '2026-11-13 13:00:00', null], [$row['status'], $row['attempts'], $row['http_status'], $row['error'], $row['source'], $row['attempted_at'], $row['synced_at']]);

        $this->states->record($this->emailId, MirrorSyncState::STATUS_OK, 'h2', 200, null, MirrorSyncState::SOURCE_JOB, $at('11:00:00'));
        $this->states->record($this->emailId, MirrorSyncState::STATUS_SKIPPED, 'h3', null, null, MirrorSyncState::SOURCE_SAVE, $at('12:00:00'));

        $row = $this->stateRow();
        $this->assertSame(['skipped', 'h3', '0', '2026-11-13 15:00:00', '2026-11-13 14:00:00'], [$row['status'], $row['content_hash'], $row['attempts'], $row['attempted_at'], $row['synced_at']], 'synced_at keeps the last success');
    }

    public function testAPageJoinsTheEmailColumnsWithTheStateAndNeverSyncedHasNullState(): void
    {
        $other = $this->email('zz-mirror-other.'.microtime(true), '');
        $this->states->record($this->emailId, MirrorSyncState::STATUS_OK, 'h', 200, null, MirrorSyncState::SOURCE_SAVE, new \DateTimeImmutable('2026-11-13 10:00:00', new \DateTimeZone('UTC')));

        $rows = $this->states->page($this->emailId - 1, 2);

        $this->assertSame([$this->emailId, $other], array_map(fn (array $r): int => (int) $r['id'], $rows));
        $this->assertSame(['Subj', '<p>x</p>', 'Pre', 'From', 'from@x.test', 'ok', 'h', '0', '2026-11-13 10:00:00'], array_values(array_diff_key($rows[0], ['id' => 1])));
        $this->assertNull($rows[1]['status']);
        $this->assertSame([], $this->states->page($other, 10), 'nothing after the last id');
    }

    public function testAnEmailRowIsReadAndADeletedOneIsNull(): void
    {
        $this->assertSame('<p>x</p>', $this->states->emailRow($this->emailId)['customHtml'] ?? null);

        $this->states->record($this->emailId, MirrorSyncState::STATUS_OK, 'h', 200, null, MirrorSyncState::SOURCE_SAVE, new \DateTimeImmutable());
        $this->db->delete('emails', ['id' => $this->emailId]);

        $this->assertNull($this->states->emailRow($this->emailId));
        $this->assertGreaterThanOrEqual(1, $this->states->pruneOrphans());
        $this->assertSame([], $this->stateRow());
    }

    public function testTheIntervalCountsOnlyItsOwnKindAndARunningRunOfItsLaneBlocksTheOther(): void
    {
        $now = new \DateTimeImmutable('2026-11-13 10:00:00', new \DateTimeZone('UTC'));

        $poll = $this->runs->claim($now, 60, PollRun::KIND_STATUS_POLL, self::SHARED_LANE);
        $this->assertNotNull($poll);
        $this->assertNull($this->runs->claim($now, 120, PollRun::KIND_MIRROR_RESYNC, self::SHARED_LANE), 'the status poll is still running');

        $this->db->update(PollRun::TABLE_NAME, ['status' => PollRun::STATUS_OK], ['id' => $poll]);
        $resync = $this->runs->claim($now, 120, PollRun::KIND_MIRROR_RESYNC, self::SHARED_LANE);
        $this->assertNotNull($resync, 'a status poll inside the interval does not count for the resync');
        $this->assertSame(PollRun::KIND_MIRROR_RESYNC, $this->db->fetchOne('SELECT kind FROM '.PollRun::TABLE_NAME.' WHERE id = ?', [$resync]));

        $this->db->update(PollRun::TABLE_NAME, ['status' => PollRun::STATUS_OK], ['id' => $resync]);
        $this->assertNull($this->runs->claim($now->modify('+30 minutes'), 120, PollRun::KIND_MIRROR_RESYNC, self::SHARED_LANE), 'not time yet for the resync');
        $this->assertNotNull($this->runs->claim($now->modify('+60 minutes'), 60, PollRun::KIND_STATUS_POLL, self::SHARED_LANE));
    }

    public function testARunningSweepNeverBlocksTheSharedLaneAndTheOtherWayRound(): void
    {
        $now = new \DateTimeImmutable('2026-11-13 10:00:00', new \DateTimeZone('UTC'));

        $sweep = $this->runs->claim($now, 60, PollRun::KIND_FAILURE_SWEEP, self::SWEEP_LANE);
        $this->assertNotNull($sweep);
        $poll = $this->runs->claim($now, 60, PollRun::KIND_STATUS_POLL, self::SHARED_LANE);
        $this->assertNotNull($poll, 'a sweep that is still running does not hold the status poll back');

        $this->db->update(PollRun::TABLE_NAME, ['status' => PollRun::STATUS_OK], ['id' => $sweep]);
        $this->assertNotNull($this->runs->claim($now->modify('+61 minutes'), 60, PollRun::KIND_FAILURE_SWEEP, self::SWEEP_LANE), 'the poll that is still running does not hold the sweep back');
    }

    public function testASweepStillRunningBlocksTheNextSweepUntilItIsTakenAsDead(): void
    {
        $now = new \DateTimeImmutable('2026-11-13 10:00:00', new \DateTimeZone('UTC'));

        $first = $this->runs->claim($now, 15, PollRun::KIND_FAILURE_SWEEP, self::SWEEP_LANE);
        $this->assertNotNull($first);
        $this->assertNull($this->runs->claim($now->modify('+30 minutes'), 15, PollRun::KIND_FAILURE_SWEEP, self::SWEEP_LANE), 'the first sweep is still running');
        $this->assertNotNull(
            $this->runs->claim($now->modify('+'.(PollRunRepository::RUNNING_STALE_MINUTES + 5).' minutes'), 15, PollRun::KIND_FAILURE_SWEEP, self::SWEEP_LANE),
            'a sweep stuck for two hours is taken as dead'
        );
    }

    public function testALaneWithNoKindIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->runs->claim(new \DateTimeImmutable(), 60, PollRun::KIND_STATUS_POLL, []);
    }

    public function testCloseEndsTheClaimedRunAndIgnoresAnEmptyOrUnknownId(): void
    {
        $now   = new \DateTimeImmutable('2026-11-13 10:00:00', new \DateTimeZone('UTC'));
        $runId = $this->runs->claim($now, 60, PollRun::KIND_STATUS_POLL, self::SHARED_LANE);
        $this->assertNotNull($runId);

        $this->runs->close(null, PollRun::STATUS_ERROR, 'no id', $now);
        $this->runs->close($runId + 100000, PollRun::STATUS_ERROR, 'no row', $now);
        $this->assertSame(PollRun::STATUS_RUNNING, $this->db->fetchOne('SELECT status FROM '.PollRun::TABLE_NAME.' WHERE id = ?', [$runId]));

        $this->runs->close($runId, PollRun::STATUS_OK, 'asked 3', new \DateTimeImmutable('2026-11-13 07:05:00', new \DateTimeZone('America/Sao_Paulo')));
        $row = $this->db->fetchAssociative('SELECT status, summary, finished_at FROM '.PollRun::TABLE_NAME.' WHERE id = ?', [$runId]);
        $this->assertSame(PollRun::STATUS_OK, $row['status']);
        $this->assertSame('asked 3', $row['summary']);
        $this->assertSame('2026-11-13 10:05:00', $row['finished_at'], 'stored in UTC');
    }

    public function testCloseRejectsAnUnknownStatus(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->runs->close(1, 'weird', 'x', new \DateTimeImmutable());
    }
}
