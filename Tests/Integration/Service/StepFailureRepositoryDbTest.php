<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Integration\Service;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\N8nDispatchBundle\Entity\StepFailure;
use MauticPlugin\N8nDispatchBundle\Entity\StepFailureRepository;
use MauticPlugin\N8nDispatchBundle\Tests\Integration\RollbackDatabaseTestCase;
use PHPUnit\Framework\Attributes\Group;

require_once __DIR__.'/../RollbackDatabaseTestCase.php';

/**
 * The SQL of StepFailureRepository against the stack's real MySQL, in a transaction rolled back at the end. Every test
 * makes its own campaign, step, contacts and logs, found by a unique name, and reads only its own rows.
 * Needs the table: run mautic:plugins:reload after deploying the plugin version that adds it.
 */
#[Group('area:alerts')]
#[Group('risk:DADOS')]
#[Group('risk:FLUXO')]
class StepFailureRepositoryDbTest extends RollbackDatabaseTestCase
{
    private StepFailureRepository $repository;
    private string $unique;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($this->db);
        $this->repository = new class($em) extends StepFailureRepository {
            public function __construct(private EntityManagerInterface $manager)
            {
            }

            protected function getEntityManager(): EntityManagerInterface
            {
                return $this->manager;
            }

            public function getTableName(): string
            {
                return StepFailure::TABLE_NAME;
            }
        };
        $this->unique = 'ZZ-stepfail-'.microtime(true).'-'.random_int(1000, 9999);
        $this->now    = new \DateTimeImmutable('2026-03-10 12:00:00', new \DateTimeZone('UTC'));
    }

    private function campaign(bool $published = true): int
    {
        return $this->insert('campaigns', ['name' => $this->unique, 'is_published' => $published ? 1 : 0, 'allow_restart' => 0]);
    }

    private function step(int $campaignId, string $name = 'step'): int
    {
        return $this->insert('campaign_events', [
            'campaign_id' => $campaignId, 'name' => $name, 'type' => 'n8ndispatch.sms.send', 'event_type' => 'action',
            'event_order' => 1, 'properties' => serialize([]), 'failed_count' => 0,
        ]);
    }

    private function lead(): int
    {
        return $this->insert('leads', ['is_published' => 1, 'points' => 0, 'email' => $this->unique.random_int(1, 1000000).'@example.test']);
    }

    private function log(int $campaignId, int $stepId, int $leadId, int $scheduled = 1, int $rotation = 1): int
    {
        return $this->insert('campaign_lead_event_log', [
            'event_id' => $stepId, 'campaign_id' => $campaignId, 'lead_id' => $leadId, 'rotation' => $rotation, 'is_scheduled' => $scheduled,
            'system_triggered' => 0, 'date_triggered' => '2026-03-10 11:00:00', 'non_action_path_taken' => 0,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function row(int $stepId, int $leadId): ?array
    {
        $row = $this->db->fetchAssociative('SELECT * FROM '.StepFailure::TABLE_NAME.' WHERE event_id = ? AND lead_id = ?', [$stepId, $leadId]);

        return false === $row ? null : $row;
    }

    /** One stuck contact: a pending log and a row with $failures failures, as the shield leaves it. */
    private function stuck(int $campaignId, int $stepId, int $failures, ?string $reason = 'HTTP 500'): int
    {
        $lead = $this->lead();
        $log  = $this->log($campaignId, $stepId, $lead);

        for ($i = 0; $i < $failures; ++$i) {
            $this->repository->record($stepId, $lead, $campaignId, $log, $reason, $this->now);
        }

        return $lead;
    }

    public function testTheFirstFailureCreatesTheRowWithOneFailure(): void
    {
        $campaign = $this->campaign();
        $step     = $this->step($campaign);
        $lead     = $this->lead();
        $log      = $this->log($campaign, $step, $lead);

        $this->repository->record($step, $lead, $campaign, $log, 'HTTP 500', new \DateTimeImmutable('2026-03-10 09:00:00', new \DateTimeZone('America/Sao_Paulo')));

        $row = $this->row($step, $lead);
        $this->assertSame(['1', (string) $log, (string) $campaign, 'HTTP 500', '2026-03-10 12:00:00', '2026-03-10 12:00:00'], [
            $row['failures'], $row['log_id'], $row['campaign_id'], $row['last_reason'], $row['first_failure_at'], $row['updated_at'],
        ], 'dates are stored in UTC');
    }

    public function testTheRetryOfTheSameLogAddsOneAndKeepsTheFirstDate(): void
    {
        $campaign = $this->campaign();
        $step     = $this->step($campaign);
        $lead     = $this->lead();
        $log      = $this->log($campaign, $step, $lead);

        $this->repository->record($step, $lead, $campaign, $log, 'HTTP 500', $this->now);
        $this->repository->record($step, $lead, $campaign, $log, 'timeout', $this->now->modify('+1 hour'));
        $this->repository->record($step, $lead, $campaign, $log, 'timeout', $this->now->modify('+2 hours'));

        $row = $this->row($step, $lead);
        $this->assertSame('3', $row['failures']);
        $this->assertSame('2026-03-10 12:00:00', $row['first_failure_at'], 'the first failure date does not move');
        $this->assertSame('2026-03-10 14:00:00', $row['updated_at']);
        $this->assertSame('timeout', $row['last_reason']);
    }

    public function testANewLogOfTheSameContactAndStepIsANewPassAndStartsAgainAtOne(): void
    {
        $campaign = $this->campaign();
        $step     = $this->step($campaign);
        $lead     = $this->lead();
        $first    = $this->log($campaign, $step, $lead);
        $second   = $this->log($campaign, $step, $lead, 1, 2);

        $this->repository->record($step, $lead, $campaign, $first, 'HTTP 500', $this->now);
        $this->repository->record($step, $lead, $campaign, $first, 'HTTP 500', $this->now);
        $this->repository->record($step, $lead, $campaign, $second, 'no phone', $this->now->modify('+5 hours'));

        $row = $this->row($step, $lead);
        $this->assertSame('1', $row['failures']);
        $this->assertSame((string) $second, $row['log_id']);
        $this->assertSame('2026-03-10 17:00:00', $row['first_failure_at'], 'a new pass has its own first failure');
        $this->assertSame('no phone', $row['last_reason']);
    }

    public function testAReasonIsCutAtTheColumnLength(): void
    {
        $campaign = $this->campaign();
        $step     = $this->step($campaign);
        $lead     = $this->lead();

        $this->repository->record($step, $lead, $campaign, $this->log($campaign, $step, $lead), str_repeat('é', 300), $this->now);

        $this->assertSame(str_repeat('é', 191), $this->row($step, $lead)['last_reason']);
    }

    public function testTwoContactsAndTwoStepsHaveTheirOwnRows(): void
    {
        $campaign = $this->campaign();
        $stepA    = $this->step($campaign);
        $stepB    = $this->step($campaign);
        $ana      = $this->lead();
        $bia      = $this->lead();
        $logAnaA  = $this->log($campaign, $stepA, $ana);

        $this->repository->record($stepA, $ana, $campaign, $logAnaA, 'x', $this->now);
        $this->repository->record($stepA, $ana, $campaign, $logAnaA, 'x', $this->now);
        $this->repository->record($stepB, $ana, $campaign, $this->log($campaign, $stepB, $ana), 'x', $this->now);
        $this->repository->record($stepA, $bia, $campaign, $this->log($campaign, $stepA, $bia), 'x', $this->now);

        $this->assertSame(['2', '1', '1'], [$this->row($stepA, $ana)['failures'], $this->row($stepB, $ana)['failures'], $this->row($stepA, $bia)['failures']]);
    }

    public function testTheCleanupKeepsTheContactStillWaitingOnItsStep(): void
    {
        $campaign = $this->campaign();
        $step     = $this->step($campaign);
        $waiting  = $this->stuck($campaign, $step, 2);

        $this->repository->deleteResolved();

        $this->assertNotNull($this->row($step, $waiting));
    }

    public function testTheCleanupDeletesTheContactWhoseRetryPassedOrWasCancelled(): void
    {
        $campaign = $this->campaign();
        $step     = $this->step($campaign);
        $passed   = $this->stuck($campaign, $step, 2);
        $this->db->executeStatement('UPDATE campaign_lead_event_log SET is_scheduled = 0 WHERE lead_id = ?', [$passed]);
        $removed = $this->stuck($campaign, $step, 2);
        $this->db->executeStatement('DELETE FROM campaign_lead_event_log WHERE lead_id = ?', [$removed]);
        $waiting = $this->stuck($campaign, $step, 2);

        $deleted = $this->repository->deleteResolved();

        $this->assertGreaterThanOrEqual(2, $deleted);
        $this->assertNull($this->row($step, $passed), 'the log is no longer scheduled');
        $this->assertNull($this->row($step, $removed), 'the log is gone');
        $this->assertNotNull($this->row($step, $waiting));
    }

    public function testTheCleanupDeletesTheRowsOfADeletedStepAndOfADeletedCampaign(): void
    {
        $campaign     = $this->campaign();
        $deletedStep  = $this->step($campaign);
        $keptStep     = $this->step($campaign);
        $onDeleted    = $this->stuck($campaign, $deletedStep, 2);
        $onKept       = $this->stuck($campaign, $keptStep, 2);
        $this->db->executeStatement("UPDATE campaign_events SET deleted = '2026-03-10 10:00:00' WHERE id = ?", [$deletedStep]);

        $goneCampaign = $this->campaign();
        $goneStep     = $this->step($goneCampaign);
        $onGone       = $this->stuck($goneCampaign, $goneStep, 2);
        $this->db->executeStatement("UPDATE campaigns SET deleted = '2026-03-10 10:00:00' WHERE id = ?", [$goneCampaign]);

        $this->repository->deleteResolved();

        $this->assertNull($this->row($deletedStep, $onDeleted));
        $this->assertNull($this->row($goneStep, $onGone));
        $this->assertNotNull($this->row($keptStep, $onKept));
    }

    public function testTheCleanupKeepsTheRowsOfAnUnpublishedCampaign(): void
    {
        $campaign = $this->campaign(false);
        $step     = $this->step($campaign);
        $lead     = $this->stuck($campaign, $step, 5);

        $this->repository->deleteResolved();

        $this->assertNotNull($this->row($step, $lead), 'the count goes on when the campaign is published again');
    }

    public function testFailingStepsCountsOnlyTheContactsAtOrOverTheThreshold(): void
    {
        $campaign = $this->campaign();
        $step     = $this->step($campaign, 'SMS cobrança');
        $this->stuck($campaign, $step, 2, 'HTTP 500');
        $this->stuck($campaign, $step, 3, 'HTTP 500');
        $this->stuck($campaign, $step, 7, 'no phone');

        $mine = $this->mine($this->repository->failingSteps(3), $campaign);

        $this->assertCount(1, $mine);
        $this->assertSame(
            ['campaign_id' => $campaign, 'campaign_name' => $this->unique, 'event_id' => $step, 'step_name' => 'SMS cobrança', 'step_type' => 'n8ndispatch.sms.send', 'contacts' => 2, 'max_failures' => 7, 'oldest' => '2026-03-10 12:00:00'],
            $mine[0]
        );
        $this->assertSame([], $this->mine($this->repository->failingSteps(8), $campaign), 'nobody has 8 failures');
    }

    public function testFailingStepsGroupsByStepAndLeavesOutAnUnpublishedCampaign(): void
    {
        $campaign = $this->campaign();
        $stepA    = $this->step($campaign);
        $stepB    = $this->step($campaign);
        $this->stuck($campaign, $stepA, 4);
        $this->stuck($campaign, $stepB, 4);
        $off     = $this->campaign(false);
        $offStep = $this->step($off);
        $this->stuck($off, $offStep, 9);

        $steps = $this->repository->failingSteps(3);

        $this->assertSame([$stepA, $stepB], array_column($this->mine($steps, $campaign), 'event_id'));
        $this->assertSame([], $this->mine($steps, $off), 'a campaign that is off raises no alert');
    }

    public function testReasonsCountTheContactsOverTheThresholdPerStepMostCommonFirst(): void
    {
        $campaign = $this->campaign();
        $step     = $this->step($campaign);
        $this->stuck($campaign, $step, 3, 'no phone');
        $this->stuck($campaign, $step, 3, 'HTTP 500');
        $this->stuck($campaign, $step, 4, 'HTTP 500');
        $this->stuck($campaign, $step, 1, 'ignored: under the threshold');

        $mine = array_values(array_filter($this->repository->reasons(3, 10), static fn (array $row): bool => $row['event_id'] === $step));

        $this->assertSame([
            ['event_id' => $step, 'reason' => 'HTTP 500', 'contacts' => 2],
            ['event_id' => $step, 'reason' => 'no phone', 'contacts' => 1],
        ], $mine);
    }

    public function testReasonsKeepTheMostCommonOnesOfEachStepUpToTheLimit(): void
    {
        $campaign = $this->campaign();
        $step     = $this->step($campaign);
        $other    = $this->step($campaign);
        $this->stuck($campaign, $step, 3, 'rare');
        $this->stuck($campaign, $step, 3, 'common');
        $this->stuck($campaign, $step, 3, 'common');
        $this->stuck($campaign, $step, 3, 'middle');
        $this->stuck($campaign, $step, 3, 'middle');
        $this->stuck($campaign, $step, 3, 'common');
        $this->stuck($campaign, $other, 3, 'only one');

        $rows = array_values(array_filter($this->repository->reasons(3, 2), static fn (array $row): bool => in_array($row['event_id'], [$step, $other], true)));

        $this->assertSame([
            ['event_id' => $step, 'reason' => 'common', 'contacts' => 3],
            ['event_id' => $step, 'reason' => 'middle', 'contacts' => 2],
            ['event_id' => $other, 'reason' => 'only one', 'contacts' => 1],
        ], $rows, 'the limit is per step: the rare reason is cut, the other step keeps its own');
    }

    public function testReasonsOfTheSameSizeAreCutByNameAndAMissingReasonIsEmpty(): void
    {
        $campaign = $this->campaign();
        $step     = $this->step($campaign);
        $this->stuck($campaign, $step, 3, 'top');
        $this->stuck($campaign, $step, 3, 'top');
        $this->stuck($campaign, $step, 3, 'top');
        $this->stuck($campaign, $step, 3, 'b tie');
        $this->stuck($campaign, $step, 3, 'b tie');
        $this->stuck($campaign, $step, 3, 'a tie');
        $this->stuck($campaign, $step, 3, 'a tie');
        $none = $this->step($campaign);
        $this->stuck($campaign, $none, 3, null);
        $this->stuck($campaign, $none, 3, '');

        $rows = array_values(array_filter($this->repository->reasons(3, 2), static fn (array $row): bool => in_array($row['event_id'], [$step, $none], true)));

        $this->assertSame([
            ['event_id' => $step, 'reason' => 'top', 'contacts' => 3],
            ['event_id' => $step, 'reason' => 'a tie', 'contacts' => 2],
            ['event_id' => $none, 'reason' => '', 'contacts' => 2],
        ], $rows, 'a tie at the cut keeps the first name, a null reason counts with the empty ones');
    }

    /**
     * @param list<array<string, mixed>> $steps
     *
     * @return list<array<string, mixed>>
     */
    private function mine(array $steps, int $campaignId): array
    {
        return array_values(array_filter($steps, static fn (array $row): bool => $row['campaign_id'] === $campaignId));
    }
}
