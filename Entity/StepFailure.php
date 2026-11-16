<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;

/**
 * How many times a contact failed on a campaign step of this plugin, in its current pass through the step. One row per
 * (step, contact), created by the first failure and kept up to date by StepFailureRepository::record() (plain SQL),
 * never through the entity manager, so the properties below are never read: they only give the schema installer the shape
 * of the table. Keep them. Nothing is written on a success: the sweep (FailingStepAlerts) deletes the rows of
 * contacts that are no longer waiting on the step. The table is created by N8nDispatchBundle::onPluginUpdate().
 */
class StepFailure
{
    public const TABLE_NAME = 'n8n_dispatch_step_failure';

    private int $eventId = 0;

    private int $leadId = 0;

    private int $campaignId = 0;

    private int $logId = 0;

    private int $failures = 0;

    private ?string $lastReason = null;

    private \DateTimeImmutable $firstFailureAt;

    private \DateTimeImmutable $updatedAt;

    /**
     * @param ORM\ClassMetadata<StepFailure> $metadata
     */
    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);

        $builder->setTable(self::TABLE_NAME)
            ->setCustomRepositoryClass(StepFailureRepository::class);

        $builder->createField('eventId', 'integer')->makePrimaryKey()->columnName('event_id')->option('unsigned', true)->build();
        $builder->createField('leadId', 'bigint')->makePrimaryKey()->columnName('lead_id')->option('unsigned', true)->build();
        $builder->addNamedField('campaignId', 'integer', 'campaign_id', false);
        $builder->addNamedField('logId', 'bigint', 'log_id', false);
        $builder->addNamedField('failures', 'integer', 'failures', false);
        $builder->addNamedField('lastReason', 'string', 'last_reason', true);
        $builder->addNamedField('firstFailureAt', 'datetime_immutable', 'first_failure_at', false);
        $builder->addNamedField('updatedAt', 'datetime_immutable', 'updated_at', false);
    }
}
