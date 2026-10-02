<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;

/**
 * One scheduled run of the status poll (see Service/StatusPollScheduler.php).
 * The row is created when a run is claimed — which is also the lock that
 * keeps two Mautic cron commands starting in the same minute from both
 * launching one — and closed by the poll command when it ends. The
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

    private ?int $id = null;

    private \DateTimeImmutable $startedAt;

    private ?\DateTimeImmutable $finishedAt = null;

    private string $status = self::STATUS_RUNNING;

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

        $builder->addIndex(['started_at'], 'n8n_dispatch_poll_run_started');
    }

    public static function start(\DateTimeInterface $now): self
    {
        $run            = new self();
        $run->startedAt = \DateTimeImmutable::createFromInterface($now);

        return $run;
    }

    public function finish(string $status, ?string $summary, \DateTimeInterface $now): void
    {
        if (!in_array($status, [self::STATUS_OK, self::STATUS_ERROR], true)) {
            throw new \InvalidArgumentException('Unknown status "'.$status.'".');
        }

        $this->status     = $status;
        $this->summary    = $summary;
        $this->finishedAt = \DateTimeImmutable::createFromInterface($now);
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
