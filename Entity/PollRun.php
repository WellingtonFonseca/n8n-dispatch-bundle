<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;

/**
 * One scheduled run of a job (see Service/JobScheduler.php).
 * The row is created when a run is claimed — which is also the lock that
 * keeps two Mautic cron commands starting in the same minute from both
 * launching one — and closed by the job's command (PollRunRepository::close()) when it ends. The
 * plugin's settings screen reads the latest one to show "last run".
 *
 * Only runs started by the scheduler leave a row; a command run by hand
 * does not. Old rows are dropped when a new run starts. The table is
 * created by N8nDispatchBundle::onPluginUpdate().
 */
class PollRun
{
    public const TABLE_NAME = 'n8n_dispatch_poll_run';

    public const STATUS_RUNNING = 'running';
    public const STATUS_OK      = 'ok';
    public const STATUS_ERROR   = 'error';

    /** Which job the run belongs to. Rows from before the Mirror resync existed are status polls. */
    public const KIND_STATUS_POLL   = 'status_poll';
    public const KIND_MIRROR_RESYNC = 'mirror_resync';

    private ?int $id = null;

    private \DateTimeImmutable $startedAt;

    private ?\DateTimeImmutable $finishedAt = null;

    private string $status = self::STATUS_RUNNING;

    private string $kind = self::KIND_STATUS_POLL;

    private ?string $summary = null;

    /**
     * @param ORM\ClassMetadata<PollRun> $metadata
     */
    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);

        $builder->setTable(self::TABLE_NAME)
            ->setCustomRepositoryClass(PollRunRepository::class);

        $builder->addId();

        $builder->addNamedField('startedAt', 'datetime_immutable', 'started_at', false);
        $builder->addNamedField('finishedAt', 'datetime_immutable', 'finished_at', true);
        $builder->addNamedField('status', 'string', 'status', false);
        $builder->addNamedField('summary', 'text', 'summary', true);
        $builder->addNamedField('kind', 'string', 'kind', false);

        $builder->addIndex(['started_at'], 'n8n_dispatch_poll_run_started');
    }

    public static function start(\DateTimeInterface $now): self
    {
        $run            = new self();
        $run->startedAt = \DateTimeImmutable::createFromInterface($now);

        return $run;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }
}
