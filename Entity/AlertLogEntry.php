<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;

/**
 * One alert that Mautic raised, and what became of it: sent to the webhook or not sent (and why). The
 * "Alertas" tab of the plugin settings lists the latest ones, so someone can see alerts that never reached n8n.
 * Rows are written by AlertLogEntryRepository::record() (plain SQL), never through the entity manager. Rows older
 * than AlertLogEntryRepository::KEEP_DAYS are dropped on the next write. The table is created by
 * N8nDispatchBundle::onPluginUpdate().
 */
class AlertLogEntry
{
    public const TABLE_NAME = 'n8n_dispatch_alert_log';

    public const OUTCOME_SENT     = 'sent';
    public const OUTCOME_NOT_SENT = 'not_sent';

    public const REASON_ALERTS_OFF      = 'alerts_off';
    public const REASON_WEBHOOK_MISSING = 'webhook_missing';
    public const REASON_REFUSED         = 'refused';
    public const REASON_ERROR           = 'error';

    private ?int $id = null;

    private \DateTimeImmutable $createdAt;

    private string $alert = '';

    private string $outcome = self::OUTCOME_SENT;

    private ?string $reason = null;

    private ?string $detail = null;

    private ?string $subject = null;

    /**
     * @param ORM\ClassMetadata<AlertLogEntry> $metadata
     */
    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);

        $builder->setTable(self::TABLE_NAME)
            ->setCustomRepositoryClass(AlertLogEntryRepository::class);

        $builder->addId();

        $builder->addNamedField('createdAt', 'datetime_immutable', 'created_at', false);
        $builder->addNamedField('alert', 'string', 'alert', false);
        $builder->addNamedField('outcome', 'string', 'outcome', false);
        $builder->addNamedField('reason', 'string', 'reason', true);
        $builder->addNamedField('detail', 'text', 'detail', true);
        $builder->addNamedField('subject', 'string', 'subject', true);

        $builder->addIndex(['created_at'], 'n8n_dispatch_alert_log_created');
    }
}
