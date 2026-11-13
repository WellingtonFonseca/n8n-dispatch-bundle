<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * The SQL of the Mirror sync state: the upsert of each sync result and the reads of the resync job. Plain DBAL, so
 * the job reads the Email columns without loading (and never changes) a Mautic Email entity.
 *
 * @extends CommonRepository<MirrorSyncState>
 */
class MirrorSyncStateRepository extends CommonRepository
{
    private const EMAIL_COLUMNS = 'e.id, e.subject, e.custom_html AS customHtml, e.preheader_text AS preheaderText, e.from_name AS fromName, e.from_address AS fromAddress';

    public function getTableAlias(): string
    {
        return 'ms';
    }

    /**
     * One row per Email, replaced on every sync. attempts counts the failures in a row (0 after anything else);
     * synced_at keeps the date of the last success.
     */
    public function record(int $emailId, string $status, string $contentHash, ?int $httpStatus, ?string $error, string $source, \DateTimeImmutable $now): void
    {
        $failed = MirrorSyncState::STATUS_FAILED === $status;
        $at     = $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $table  = $this->getTableName();

        $this->getEntityManager()->getConnection()->executeStatement(
            "INSERT INTO {$table} (email_id, status, content_hash, http_status, error, attempts, source, attempted_at, synced_at) "
            .'VALUES (:id, :status, :hash, :http, :error, :attempts, :source, :at, :synced) '
            .'ON DUPLICATE KEY UPDATE status = VALUES(status), content_hash = VALUES(content_hash), http_status = VALUES(http_status), '
            .'error = VALUES(error), attempts = IF(VALUES(attempts) > 0, attempts + 1, 0), source = VALUES(source), '
            .'attempted_at = VALUES(attempted_at), synced_at = COALESCE(VALUES(synced_at), synced_at)',
            [
                'id'       => $emailId,
                'status'   => $status,
                'hash'     => $contentHash,
                'http'     => $httpStatus,
                'error'    => null === $error ? null : mb_substr($error, 0, 2000),
                'attempts' => $failed ? 1 : 0,
                'source'   => $source,
                'at'       => $at,
                'synced'   => MirrorSyncState::STATUS_OK === $status ? $at : null,
            ]
        );
    }

    /**
     * The Emails after $afterId, in id order, each with the state of its last sync (null columns when never synced).
     * The dates are UTC strings.
     *
     * @return list<array<string, mixed>>
     */
    public function page(int $afterId, int $limit): array
    {
        $table = $this->getTableName();

        return $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT '.self::EMAIL_COLUMNS.', ms.status, ms.content_hash AS contentHash, ms.attempts, ms.attempted_at AS attemptedAt, ms.synced_at AS syncedAt '
            .'FROM '.MAUTIC_TABLE_PREFIX."emails e LEFT JOIN {$table} ms ON ms.email_id = e.id "
            .'WHERE e.id > :after ORDER BY e.id LIMIT '.max(1, $limit),
            ['after' => $afterId]
        );
    }

    /**
     * The Email's current columns, read right before a send so the job never sends content older than a save made
     * while it ran. Null when the Email was deleted.
     *
     * @return array<string, mixed>|null
     */
    public function emailRow(int $emailId): ?array
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT '.self::EMAIL_COLUMNS.' FROM '.MAUTIC_TABLE_PREFIX.'emails e WHERE e.id = :id',
            ['id' => $emailId]
        );

        return false === $row ? null : $row;
    }

    /** Drops the state of Emails that no longer exist. */
    public function pruneOrphans(): int
    {
        $table = $this->getTableName();

        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            "DELETE ms FROM {$table} ms LEFT JOIN ".MAUTIC_TABLE_PREFIX.'emails e ON e.id = ms.email_id WHERE e.id IS NULL'
        );
    }
}
