<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service;

use MauticPlugin\N8nDispatchBundle\Entity\MirrorSyncState;
use MauticPlugin\N8nDispatchBundle\Entity\MirrorSyncStateRepository;
use MauticPlugin\N8nDispatchBundle\Service\CallPacer;
use MauticPlugin\N8nDispatchBundle\Service\MirrorResync;
use MauticPlugin\N8nDispatchBundle\Service\MirrorSync;
use MauticPlugin\N8nDispatchBundle\Service\MirrorSyncPayload;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class MirrorResyncTest extends TestCase
{
    /** @var MirrorSyncStateRepository&MockObject */
    private MirrorSyncStateRepository $states;

    /** @var MirrorSync&MockObject */
    private MirrorSync $mirrorSync;

    private MirrorSyncPayload $payload;

    /** @var list<int> milliseconds the pacer was asked to sleep */
    private array $slept = [];

    private MirrorResync $resync;

    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->states     = $this->createMock(MirrorSyncStateRepository::class);
        $this->mirrorSync = $this->createMock(MirrorSync::class);
        $this->payload    = new MirrorSyncPayload();
        $pacer            = new CallPacer(function (int $ms): void { $this->slept[] = $ms; });
        $this->resync     = new MirrorResync($this->states, $this->mirrorSync, $this->payload, $pacer);
        $this->now        = new \DateTimeImmutable('2026-11-13 12:00:00', new \DateTimeZone('UTC'));
        $this->mirrorSync->method('isConfigured')->willReturn(true);
    }

    /**
     * @return array<string, mixed>
     */
    private function email(int $id, string $html = '<p>x</p>'): array
    {
        return ['id' => $id, 'subject' => 'S'.$id, 'customHtml' => $html, 'preheaderText' => null, 'fromName' => 'N', 'fromAddress' => 'a@b.test'];
    }

    /**
     * An Email row with the state of its last sync; $sameContent false gives a stale content hash.
     *
     * @return array<string, mixed>
     */
    private function row(int $id, ?string $status, bool $sameContent = true, int $attempts = 0, ?string $attemptedAt = '2026-11-13 11:00:00'): array
    {
        $email = $this->email($id);
        $hash  = $sameContent ? $this->payload->contentHash($email) : 'stale';

        return $email + ['status' => $status, 'contentHash' => null === $status ? null : $hash, 'attempts' => $attempts, 'attemptedAt' => null === $status ? null : $attemptedAt];
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function givenRows(array $rows): void
    {
        $this->states->method('page')->willReturnCallback(fn (int $afterId): array => array_values(array_filter($rows, fn (array $r): bool => $r['id'] > $afterId)));
        $this->states->method('emailRow')->willReturnCallback(fn (int $id): array => $this->email($id));
    }

    public function testTheOrderIsFailedThenNeverThenChangedThenTheOldestRefresh(): void
    {
        $this->givenRows([
            $this->row(1, MirrorSyncState::STATUS_OK, true, 0, '2026-11-12 08:00:00'),  // refresh, older
            $this->row(2, MirrorSyncState::STATUS_OK, false),                           // changed
            $this->row(3, null),                                                         // never
            $this->row(4, MirrorSyncState::STATUS_FAILED, true, 3),                      // failed
            $this->row(5, MirrorSyncState::STATUS_OK, true, 0, '2026-11-12 10:00:00'),  // refresh, newer
            $this->row(6, MirrorSyncState::STATUS_OK),                                   // in sync: not due
        ]);

        $this->assertSame([4, 3, 2, 1, 5], $this->resync->dueEmailIds($this->now, 24));
    }

    public function testInsideAPriorityTheOldestAttemptGoesFirst(): void
    {
        $this->givenRows([
            $this->row(1, MirrorSyncState::STATUS_FAILED, true, 1, '2026-11-13 11:00:00'),
            $this->row(2, MirrorSyncState::STATUS_FAILED, true, 1, '2026-11-13 09:00:00'),
            $this->row(3, MirrorSyncState::STATUS_OK, false, 0, '2026-11-13 11:30:00'),
            $this->row(4, MirrorSyncState::STATUS_OK, false, 0, '2026-11-13 10:30:00'),
        ]);

        $this->assertSame([2, 1, 4, 3], $this->resync->dueEmailIds($this->now, 24));
    }

    public function testPastTheDeadlineNoNewEmailIsTakenAndTheRestIsLeft(): void
    {
        $this->givenRows([$this->row(1, null), $this->row(2, null), $this->row(3, null)]);
        $this->mirrorSync->expects($this->never())->method('sync');

        $summary = $this->resync->run(2, 0, 24, $this->now, 0);

        $this->assertSame(['due' => 3, 'calls' => 0, 'ok' => 0, 'failed' => 0, 'skipped' => 0, 'left' => 3, 'error' => null], $summary);
    }

    public function testADeadlineReachedDuringTheRunLeavesTheEmailsNotTaken(): void
    {
        $this->givenRows([$this->row(1, null), $this->row(2, null), $this->row(3, null), $this->row(4, null)]);
        $this->mirrorSync->expects($this->once())->method('sync')->willReturnCallback(function (): string {
            usleep(60000); // the first call outlasts the deadline

            return MirrorSyncState::STATUS_OK;
        });

        $summary = $this->resync->run(3, 0, 24, $this->now, 0.05);

        $this->assertSame([1, 3], [$summary['ok'], $summary['left']], 'two of the batch not taken plus one outside the batch');
    }

    public function testARunEndsWellBeforeARunIsTakenAsDead(): void
    {
        $this->assertLessThanOrEqual(\MauticPlugin\N8nDispatchBundle\Entity\PollRunRepository::RUNNING_STALE_MINUTES * 60 / 2, MirrorResync::MAX_RUN_SECONDS + MirrorSync::MAX_DURATION);
    }

    public function testRunOneOfAMissingEmailIsAnError(): void
    {
        $this->states->method('emailRow')->willReturn(null);
        $this->mirrorSync->expects($this->never())->method('sync');

        $this->assertSame('email 77 not found', $this->resync->runOne(77)['error']);
    }

    public function testABatchOfOneSendsOneEmail(): void
    {
        $this->givenRows([$this->row(1, null), $this->row(2, null)]);
        $this->mirrorSync->expects($this->once())->method('sync')->with(1)->willReturn(MirrorSyncState::STATUS_OK);

        $this->assertSame(1, $this->resync->run(1, 0, 24, $this->now)['left']);
    }

    public function testASyncExactlyAtTheRefreshLimitIsNotDueAndOneSecondOlderIs(): void
    {
        $this->givenRows([
            $this->row(1, MirrorSyncState::STATUS_OK, true, 0, '2026-11-12 12:00:00'),
            $this->row(2, MirrorSyncState::STATUS_OK, true, 0, '2026-11-12 11:59:59'),
        ]);

        $this->assertSame([2], $this->resync->dueEmailIds($this->now, 24));
    }

    public function testAnEmailFailedTenTimesWaitsForTheRefreshByItsLastAttempt(): void
    {
        $this->givenRows([
            $this->row(1, MirrorSyncState::STATUS_FAILED, true, 9),
            $this->row(2, MirrorSyncState::STATUS_FAILED, true, 10, '2026-11-13 10:00:00'),
            $this->row(3, MirrorSyncState::STATUS_FAILED, true, 10, '2026-11-12 10:00:00'),
        ]);

        $this->assertSame([1, 3], $this->resync->dueEmailIds($this->now, 24));
    }

    public function testASkippedEmailIsDueOnlyWhenItsContentChanges(): void
    {
        $this->givenRows([
            $this->row(1, MirrorSyncState::STATUS_SKIPPED, true, 0, '2026-01-01 00:00:00'),
            $this->row(2, MirrorSyncState::STATUS_SKIPPED, false),
        ]);

        $this->assertSame([2], $this->resync->dueEmailIds($this->now, 24));
    }

    public function testTheRowsAreReadPageAfterPageUntilAShortPage(): void
    {
        $ids  = range(1, 101);
        $rows = array_map(fn (int $id): array => $this->row($id, null), $ids);
        $this->states->expects($this->exactly(2))->method('page')
            ->willReturnCallback(fn (int $afterId, int $limit): array => array_slice(array_values(array_filter($rows, fn (array $r): bool => $r['id'] > $afterId)), 0, $limit));

        $this->assertSame($ids, $this->resync->dueEmailIds($this->now, 24));
    }

    public function testARunSendsTheBatchOneByOneWithTheFreshColumnsAndCountsTheRest(): void
    {
        $this->givenRows([$this->row(1, null), $this->row(2, null), $this->row(3, null)]);
        $sent = [];
        $this->mirrorSync->method('sync')->willReturnCallback(function (int $id, array $fields, ?string $by, string $source) use (&$sent): string {
            $sent[] = [$id, $fields['subject'], $by, $source];

            return 1 === $id ? MirrorSyncState::STATUS_FAILED : MirrorSyncState::STATUS_OK;
        });
        $this->states->expects($this->once())->method('pruneOrphans');

        $summary = $this->resync->run(2, 0, 24, $this->now);

        $this->assertSame([[1, 'S1', null, MirrorSyncState::SOURCE_JOB], [2, 'S2', null, MirrorSyncState::SOURCE_JOB]], $sent);
        $this->assertSame(['due' => 3, 'calls' => 2, 'ok' => 1, 'failed' => 1, 'skipped' => 0, 'left' => 1, 'error' => null], $summary);
    }

    public function testThePauseIsKeptBetweenCallsButNotBeforeTheFirstNorForAnEmptyEmail(): void
    {
        $this->states->method('page')->willReturnCallback(fn (int $afterId): array => $afterId > 0 ? [] : [$this->row(1, null), $this->row(2, null), $this->row(3, null)]);
        $this->states->method('emailRow')->willReturnCallback(fn (int $id): array => $this->email($id, 2 === $id ? '' : '<p>x</p>'));
        $this->mirrorSync->method('sync')->willReturnCallback(fn (int $id): string => 2 === $id ? MirrorSyncState::STATUS_SKIPPED : MirrorSyncState::STATUS_OK);
        $summary = $this->resync->run(10, 60000, 24, $this->now);

        $this->assertCount(1, $this->slept, 'one pause: between Email 1 and Email 3, none before the first, none for the empty one');
        $this->assertGreaterThan(59000, $this->slept[0]);
        $this->assertSame(2, $summary['calls']);
        $this->assertSame(1, $summary['skipped']);
    }

    public function testADeletedEmailIsLeftOutOfTheRun(): void
    {
        $this->states->method('page')->willReturnCallback(fn (int $afterId): array => $afterId > 0 ? [] : [$this->row(1, null), $this->row(2, null)]);
        $this->states->method('emailRow')->willReturnCallback(fn (int $id): ?array => 1 === $id ? null : $this->email($id));
        $this->mirrorSync->expects($this->once())->method('sync')->with(2)->willReturn(MirrorSyncState::STATUS_OK);

        $this->assertSame(1, $this->resync->run(10, 0, 24, $this->now)['ok']);
    }

    public function testWithoutAWebhookNothingIsReadOrSent(): void
    {
        $mirrorSync = $this->createMock(MirrorSync::class);
        $mirrorSync->method('isConfigured')->willReturn(false);
        $mirrorSync->expects($this->never())->method('sync');
        $this->states->expects($this->never())->method('page');
        $resync = new MirrorResync($this->states, $mirrorSync, $this->payload, new CallPacer());

        $this->assertSame('the integration is off or has no webhook URL', $resync->run(10, 0, 24, $this->now)['error']);
        $this->assertSame('the integration is off or has no webhook URL', $resync->runOne(5)['error']);
    }

    public function testAWebhookRemovedDuringTheRunStopsItWithAnError(): void
    {
        $this->givenRows([$this->row(1, null), $this->row(2, null)]);
        $this->mirrorSync->expects($this->once())->method('sync')->willReturn(null);

        $summary = $this->resync->run(10, 0, 24, $this->now);

        $this->assertSame('the integration was turned off during the run', $summary['error']);
        $this->assertSame([0, 2], [$summary['ok'], $summary['left']], 'the Email not sent and the one after it are left');
    }

    public function testAWebhookRemovedAfterTheFirstEmailLeavesTheRest(): void
    {
        $this->givenRows([$this->row(1, null), $this->row(2, null), $this->row(3, null)]);
        $this->mirrorSync->method('sync')->willReturnOnConsecutiveCalls(MirrorSyncState::STATUS_OK, null);

        $summary = $this->resync->run(10, 0, 24, $this->now);

        $this->assertSame([1, 2], [$summary['ok'], $summary['left']], 'Email 2 (not sent) and Email 3 are left');
    }

    public function testRunOneSendsThatEmailWhateverItsState(): void
    {
        $this->states->method('emailRow')->with(9)->willReturn($this->email(9));
        $this->states->expects($this->never())->method('page');
        $this->mirrorSync->expects($this->once())->method('sync')->with(9)->willReturn(MirrorSyncState::STATUS_OK);

        $this->assertSame(['due' => 1, 'calls' => 1, 'ok' => 1, 'failed' => 0, 'skipped' => 0, 'left' => 0, 'error' => null], $this->resync->runOne(9));
    }
}
