<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use MauticPlugin\N8nDispatchBundle\Service\DispatchLogReader;
use MauticPlugin\N8nDispatchBundle\Service\StatusBackfill;
use MauticPlugin\N8nDispatchBundle\Service\StatusTracker;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class StatusBackfillTest extends TestCase
{
    /** @var Connection&MockObject */
    private Connection $connection;

    /** @var StatusTracker&MockObject */
    private StatusTracker $tracker;

    private StatusBackfill $backfill;

    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $metadata         = $this->createMock(ClassMetadata::class);
        $metadata->method('getTableName')->willReturn('tbl');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($this->connection);
        $em->method('getClassMetadata')->willReturn($metadata);

        $this->tracker  = $this->createMock(StatusTracker::class);
        $this->backfill = new StatusBackfill($em, new DispatchLogReader(), $this->tracker);
        $this->now      = new \DateTimeImmutable('2026-10-02 12:00:00', new \DateTimeZone('UTC'));
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @return array<string, mixed>
     */
    private function row(int $id, string $type, array $metadata, string $when = '2026-09-29 14:00:00'): array
    {
        return ['id' => (string) $id, 'type' => $type, 'date_triggered' => $when, 'metadata' => serialize($metadata)];
    }

    private function emailMeta(int $id): array
    {
        return ['logSendEmailId' => $id, 'n8ndispatch' => ['status' => 'production', 'httpStatusCode' => 200]];
    }

    public function testRegistersTheDispatchesOfEachChannelWithTheirOriginalDate(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturnOnConsecutiveCalls([
            $this->row(1, 'n8ndispatch.email.send', $this->emailMeta(4001)),
            $this->row(2, 'n8ndispatch.sms.send', ['logSendSmsId' => 9001, 'n8ndispatch' => ['status' => 'production', 'httpStatusCode' => 200]], '2026-09-30 09:30:00'),
        ], []);

        $calls = [];
        $this->tracker->method('register')->willReturnCallback(function (string $channel, array $refs, \DateTimeImmutable $when) use (&$calls): int {
            $calls[] = [$channel, $refs, $when->format('Y-m-d H:i:s T')];

            return count($refs);
        });

        $summary = $this->backfill->run(7, 500, false, $this->now);

        $this->assertSame([
            ['email', ['logSendEmailId' => 4001], '2026-09-29 14:00:00 UTC'],
            ['sms', ['logSendSmsId' => 9001], '2026-09-30 09:30:00 UTC'],
        ], $calls);
        $this->assertSame(['scanned' => 2, 'eligible' => 2, 'registered' => 2, 'alreadyTracked' => 0, 'skipped' => 0], $summary);
    }

    public function testCountsWhatWasAlreadyTrackedAndRegistersNothingTwice(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturnOnConsecutiveCalls([
            $this->row(1, 'n8ndispatch.hsm.send', ['logSendHsmId' => 5, 'logSendHsmUuid' => 'u', 'n8ndispatch' => ['status' => 'production', 'httpStatusCode' => 200]]),
        ], []);
        // the tracker reports it created only one of the two rows
        $this->tracker->method('register')->willReturn(1);

        $summary = $this->backfill->run(7, 500, false, $this->now);

        $this->assertSame(1, $summary['registered']);
        $this->assertSame(1, $summary['alreadyTracked']);
    }

    public function testRowsThatAreNotRealDispatchesOrCannotBeReadAreSkipped(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturnOnConsecutiveCalls([
            $this->row(1, 'n8ndispatch.email.send', ['logSendEmailId' => 1, 'n8ndispatch' => ['status' => 'test']]),
            ['id' => '2', 'type' => 'n8ndispatch.email.send', 'date_triggered' => '2026-09-29 14:00:00', 'metadata' => 'not serialized at all'],
            ['id' => '3', 'type' => 'n8ndispatch.email.send', 'date_triggered' => '2026-09-29 14:00:00', 'metadata' => null],
            $this->row(4, 'something.else', $this->emailMeta(7)),
        ], []);
        $this->tracker->expects($this->never())->method('register');

        $summary = $this->backfill->run(7, 500, false, $this->now);

        $this->assertSame(['scanned' => 4, 'eligible' => 0, 'registered' => 0, 'alreadyTracked' => 0, 'skipped' => 4], $summary);
    }

    public function testDryRunWritesNothingAndCountsWhatWouldBeRegistered(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturnOnConsecutiveCalls([
            $this->row(1, 'n8ndispatch.email.send', $this->emailMeta(4001)),
            $this->row(2, 'n8ndispatch.email.send', $this->emailMeta(4002)),
        ], []);
        $this->tracker->method('isTracked')->willReturnCallback(fn (string $type, string $value): bool => '4002' === $value);
        $this->tracker->expects($this->never())->method('register');

        $summary = $this->backfill->run(7, 500, true, $this->now);

        $this->assertSame(1, $summary['registered'], 'one would be registered');
        $this->assertSame(1, $summary['alreadyTracked']);
        $this->assertSame(2, $summary['eligible']);
    }

    public function testWalksThroughEveryPageAndStopsOnAShortOne(): void
    {
        $this->connection->expects($this->exactly(2))->method('fetchAllAssociative')
            ->willReturnOnConsecutiveCalls(
                [$this->row(10, 'n8ndispatch.email.send', $this->emailMeta(1)), $this->row(11, 'n8ndispatch.email.send', $this->emailMeta(2))],
                [$this->row(12, 'n8ndispatch.email.send', $this->emailMeta(3))]
            );
        $this->tracker->method('register')->willReturn(1);

        $summary = $this->backfill->run(7, 2, false, $this->now);

        $this->assertSame(3, $summary['scanned']);
        $this->assertSame(3, $summary['registered']);
    }

    public function testAFullLastPageIsFollowedByOneMoreRead(): void
    {
        $this->connection->expects($this->exactly(2))->method('fetchAllAssociative')
            ->willReturnOnConsecutiveCalls(
                [$this->row(10, 'n8ndispatch.email.send', $this->emailMeta(1)), $this->row(11, 'n8ndispatch.email.send', $this->emailMeta(2))],
                []
            );
        $this->tracker->method('register')->willReturn(1);

        $this->assertSame(2, $this->backfill->run(7, 2, false, $this->now)['scanned']);
    }

    public function testEachPageContinuesAfterTheLastLogRead(): void
    {
        $seen = [];
        $this->connection->method('fetchAllAssociative')->willReturnCallback(function (string $sql, array $params) use (&$seen): array {
            $seen[] = $params['last'];

            return 0 === $params['last'] ? [$this->row(10, 'n8ndispatch.email.send', $this->emailMeta(1)), $this->row(11, 'n8ndispatch.email.send', $this->emailMeta(2))] : [];
        });
        $this->tracker->method('register')->willReturn(1);

        $this->backfill->run(7, 2, false, $this->now);

        $this->assertSame([0, 11], $seen);
    }

    public function testTheSearchStartsSinceTheGivenNumberOfDays(): void
    {
        $this->connection->expects($this->once())->method('fetchAllAssociative')
            ->with($this->anything(), $this->callback(fn (array $p): bool => '2026-09-25 12:00:00' === $p['since'] && 0 === $p['last']))
            ->willReturn([]);

        $this->backfill->run(7, 500, false, $this->now);
    }
}
