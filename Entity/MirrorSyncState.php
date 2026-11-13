<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;

/**
 * The result of the last email.save sync of one Email: what the resync job reads to find the Emails that are out of
 * sync with Mirror. Written only by MirrorSyncStateRepository::record() (an upsert); this class only maps the table,
 * which N8nDispatchBundle::onPluginUpdate() creates.
 */
class MirrorSyncState
{
    public const TABLE_NAME = 'n8n_dispatch_mirror_sync';

    public const STATUS_OK      = 'ok';
    public const STATUS_FAILED  = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    public const SOURCE_SAVE = 'save';
    public const SOURCE_JOB  = 'job';

    private int $emailId;

    private string $status;

    private string $contentHash;

    private ?int $httpStatus = null;

    private ?string $error = null;

    private int $attempts = 0;

    private string $source;

    private \DateTimeImmutable $attemptedAt;

    private ?\DateTimeImmutable $syncedAt = null;

    /**
     * @param ORM\ClassMetadata<MirrorSyncState> $metadata
     */
    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);

        $builder->setTable(self::TABLE_NAME)
            ->setCustomRepositoryClass(MirrorSyncStateRepository::class);

        $builder->createField('emailId', 'integer')->makePrimaryKey()->columnName('email_id')->build();
        $builder->addNamedField('status', 'string', 'status', false);
        $builder->addNamedField('contentHash', 'string', 'content_hash', false);
        $builder->addNamedField('httpStatus', 'integer', 'http_status', true);
        $builder->addNamedField('error', 'text', 'error', true);
        $builder->addNamedField('attempts', 'integer', 'attempts', false);
        $builder->addNamedField('source', 'string', 'source', false);
        $builder->addNamedField('attemptedAt', 'datetime_immutable', 'attempted_at', false);
        $builder->addNamedField('syncedAt', 'datetime_immutable', 'synced_at', true);
    }
}
