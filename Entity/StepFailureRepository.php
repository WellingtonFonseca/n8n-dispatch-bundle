<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * The SQL of the step failure table (StepFailure). Plain statements on purpose: a failure is recorded inside core's
 * failure handling, in the middle of a batch, where an entity manager flush must not happen.
 *
 * @extends CommonRepository<StepFailure>
 */
class StepFailureRepository extends CommonRepository
{
    private const MAX_REASON_LENGTH = 191;

    public function getTableAlias(): string
    {
        return 'sf';
    }

    /**
     * Counts one more failure of the contact on the step. The same log (core's retry of the contact) adds 1. A different
     * log is a new pass through the step (reentry, a loop): the count starts again at 1. One statement, so two cron
     * processes cannot lose a failure. The assignments run left to right and each sees the values set before it, so
     * `log_id`, which they all compare, is assigned last.
     */
    public function record(int $eventId, int $leadId, int $campaignId, int $logId, ?string $reason, \DateTimeImmutable $now): void
    {
        $table = $this->getTableName();
        $date  = $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $this->getEntityManager()->getConnection()->executeStatement(
            "INSERT INTO {$table} (event_id, lead_id, campaign_id, log_id, failures, last_reason, first_failure_at, updated_at) "
            .'VALUES (:event, :lead, :campaign, :log, 1, :reason, :now, :now) '
            .'ON DUPLICATE KEY UPDATE '
            .'failures = IF(log_id = VALUES(log_id), failures + 1, 1), '
            .'first_failure_at = IF(log_id = VALUES(log_id), first_failure_at, VALUES(first_failure_at)), '
            .'campaign_id = VALUES(campaign_id), '
            .'last_reason = VALUES(last_reason), '
            .'updated_at = VALUES(updated_at), '
            .'log_id = VALUES(log_id)',
            [
                'event'    => $eventId,
                'lead'     => $leadId,
                'campaign' => $campaignId,
                'log'      => $logId,
                'reason'   => null === $reason ? null : mb_substr($reason, 0, self::MAX_REASON_LENGTH),
                'now'      => $date,
            ]
        );
    }

    /**
     * Deletes the rows of contacts that no longer wait on the step: the retry passed, the event was cancelled on the
     * Timeline, the contact left the campaign, or the step or the campaign was deleted. Returns how many rows went.
     */
    public function deleteResolved(): int
    {
        $table  = $this->getTableName();
        $prefix = MAUTIC_TABLE_PREFIX;

        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            "DELETE f FROM {$table} f"
            .' LEFT JOIN '.$prefix.'campaign_lead_event_log l ON l.id = f.log_id AND l.is_scheduled = 1'
            .' LEFT JOIN '.$prefix.'campaign_events e ON e.id = f.event_id AND e.deleted IS NULL'
            .' LEFT JOIN '.$prefix.'campaigns c ON c.id = f.campaign_id AND c.deleted IS NULL'
            .' WHERE l.id IS NULL OR e.id IS NULL OR c.id IS NULL'
        );
    }

    /**
     * The steps of PUBLISHED campaigns that hold at least one contact at or over the threshold. A campaign that is off
     * keeps its rows (core does not retry it, and the count goes on when it is published again) but raises no alert.
     *
     * @return list<array{campaign_id: int, campaign_name: string, event_id: int, step_name: string, step_type: string, contacts: int, max_failures: int, oldest: string}>
     */
    public function failingSteps(int $threshold): array
    {
        $table  = $this->getTableName();
        $prefix = MAUTIC_TABLE_PREFIX;
        $rows   = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT f.campaign_id, c.name AS campaign_name, f.event_id, e.name AS step_name, e.type AS step_type,'
            .' COUNT(*) AS contacts, MAX(f.failures) AS max_failures, MIN(f.first_failure_at) AS oldest'
            ." FROM {$table} f"
            .' JOIN '.$prefix.'campaigns c ON c.id = f.campaign_id AND c.is_published = 1'
            .' JOIN '.$prefix.'campaign_events e ON e.id = f.event_id'
            .' WHERE f.failures >= :threshold'
            .' GROUP BY f.campaign_id, c.name, f.event_id, e.name, e.type'
            .' ORDER BY f.campaign_id, f.event_id',
            ['threshold' => $threshold]
        );

        return array_map(static fn (array $row): array => [
            'campaign_id'   => (int) $row['campaign_id'],
            'campaign_name' => (string) $row['campaign_name'],
            'event_id'      => (int) $row['event_id'],
            'step_name'     => (string) $row['step_name'],
            'step_type'     => (string) $row['step_type'],
            'contacts'      => (int) $row['contacts'],
            'max_failures'  => (int) $row['max_failures'],
            'oldest'        => (string) $row['oldest'],
        ], $rows);
    }

    /**
     * The reasons of the contacts at or over the threshold, most common first, at most `$perStep` for each step. The
     * limit is in the query so a step whose reasons carry contact data (one reason per contact) never loads them all.
     *
     * @return list<array{event_id: int, reason: string, contacts: int}>
     */
    public function reasons(int $threshold, int $perStep): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT event_id, reason, contacts FROM ('
            .'SELECT event_id, reason, contacts, ROW_NUMBER() OVER (PARTITION BY event_id ORDER BY contacts DESC, reason) AS rank_in_step FROM ('
            .'SELECT event_id, COALESCE(last_reason, \'\') AS reason, COUNT(*) AS contacts FROM '.$this->getTableName()
            .' WHERE failures >= :threshold GROUP BY event_id, reason) AS grouped) AS ranked'
            .' WHERE rank_in_step <= :perStep ORDER BY event_id, rank_in_step',
            ['threshold' => $threshold, 'perStep' => $perStep]
        );

        return array_map(static fn (array $row): array => [
            'event_id' => (int) $row['event_id'],
            'reason'   => (string) $row['reason'],
            'contacts' => (int) $row['contacts'],
        ], $rows);
    }
}
