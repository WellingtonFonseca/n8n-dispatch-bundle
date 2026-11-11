<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * @extends CommonRepository<AlertLogEntry>
 */
class AlertLogEntryRepository extends CommonRepository
{
    public const KEEP_DAYS = 30;

    public function getTableAlias(): string
    {
        return 'al';
    }

    /**
     * @param string      $outcome one of AlertLogEntry::OUTCOME_*
     * @param string|null $reason  one of AlertLogEntry::REASON_*, empty when sent
     */
    public function record(\DateTimeImmutable $now, string $alert, string $outcome, ?string $reason, ?string $detail, ?string $subject): void
    {
        $connection = $this->getEntityManager()->getConnection();
        $table      = $this->getTableName();
        $utc        = new \DateTimeZone('UTC');

        $connection->executeStatement(
            "DELETE FROM {$table} WHERE created_at < :oldest",
            ['oldest' => $now->setTimezone($utc)->modify('-'.self::KEEP_DAYS.' days')->format('Y-m-d H:i:s')]
        );
        $connection->insert($table, [
            'created_at' => $now->setTimezone($utc)->format('Y-m-d H:i:s'),
            'alert'      => $alert,
            'outcome'    => $outcome,
            'reason'     => $reason,
            'detail'     => null === $detail ? null : mb_substr($detail, 0, 1000),
            'subject'    => null === $subject ? null : mb_substr($subject, 0, 191),
        ]);
    }

    /**
     * @return list<array{created_at: string, alert: string, outcome: string, reason: string|null, detail: string|null, subject: string|null}>
     */
    public function latest(int $limit): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT created_at, alert, outcome, reason, detail, subject FROM '.$this->getTableName().' ORDER BY created_at DESC, id DESC LIMIT '.max(1, $limit)
        );

        return array_values($rows);
    }
}
