<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;

/**
 * The history of one campaign log (one dispatch of a contact), in a single
 * list with two kinds of entry:
 *  - 'callback': each time n8n reported an outcome different from the
 *    previous one for a DispatchTracking row (so an id that stays 'pending'
 *    for hours adds nothing here — see DispatchTracking::applyOutcome());
 *  - 'dispatch': each attempt to send to n8n (the first one and every
 *    resend), accepted ('success') or refused ('failed'), with n8n's
 *    message when it refused.
 * Nothing is ever overwritten or deleted, not even when a resend drops the
 * tracking rows of the dispatch it replaces: entries are tied to the log by
 * campaign_log_id, so a later 'success' still leaves the earlier 'error'
 * visible on the Timeline card.
 *
 * The table is created by N8nDispatchBundle::onPluginUpdate().
 */
class DispatchCallback
{
    public const TABLE_NAME = 'n8n_dispatch_callback';

    private ?int $id = null;

    public const KIND_CALLBACK = 'callback';
    public const KIND_DISPATCH = 'dispatch';

    public const DISPATCH_SUCCESS = 'success';
    public const DISPATCH_FAILED  = 'failed';
    /** Not sent: the step was in 'test' or 'paused' mode, so n8n was never called. */
    public const DISPATCH_TEST   = 'test';
    public const DISPATCH_PAUSED = 'paused';

    /** The tracking row that produced a 'callback' entry; null on a 'dispatch' entry (and kept as is after the row is dropped). */
    private ?int $trackingId = null;

    private ?int $campaignLogId = null;

    private string $kind = self::KIND_CALLBACK;

    private ?string $refType = null;

    private ?string $refValue = null;

    private string $outcome;

    private ?string $message = null;

    private ?string $bodyJson = null;

    private \DateTimeImmutable $receivedAt;

    /**
     * @param ORM\ClassMetadata<DispatchCallback> $metadata
     */
    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);

        $builder->setTable(self::TABLE_NAME)
            ->setCustomRepositoryClass(DispatchCallbackRepository::class);

        $builder->addId();

        $builder->addNamedField('trackingId', 'integer', 'tracking_id', true);
        $builder->addNamedField('campaignLogId', 'integer', 'campaign_log_id', true);
        $builder->addNamedField('kind', 'string', 'kind', false);
        $builder->addNamedField('refType', 'string', 'ref_type', true);
        $builder->createField('refValue', 'string')->columnName('ref_value')->length(191)->nullable()->build();
        $builder->addNamedField('outcome', 'string', 'outcome', false);
        $builder->addNamedField('message', 'text', 'message', true);
        $builder->addNamedField('bodyJson', 'text', 'body_json', true);
        $builder->addNamedField('receivedAt', 'datetime_immutable', 'received_at', false);

        $builder->addIndex(['tracking_id'], 'n8n_dispatch_callback_tracking');
        $builder->addIndex(['campaign_log_id'], 'n8n_dispatch_callback_log');
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function create(int $trackingId, string $outcome, ?string $message, array $body, \DateTimeInterface $now): self
    {
        $callback = new self();
        $callback->setTrackingId($trackingId);
        $callback->setOutcome($outcome);
        $callback->setMessage($message);
        $callback->bodyJson = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;
        $callback->setReceivedAt($now);

        return $callback;
    }

    /**
     * An attempt to send to n8n: accepted ($accepted) or refused, with the
     * ref (the id n8n returned, when it returned one) and n8n's message.
     */
    public static function createDispatch(int $campaignLogId, bool $accepted, ?string $refType, ?string $refValue, ?string $message, \DateTimeInterface $now): self
    {
        $dispatch = new self();
        $dispatch->kind          = self::KIND_DISPATCH;
        $dispatch->campaignLogId = $campaignLogId;
        $dispatch->outcome       = $accepted ? self::DISPATCH_SUCCESS : self::DISPATCH_FAILED;
        $dispatch->refType       = $refType;
        $dispatch->refValue      = $refValue;
        $dispatch->message       = $message;
        $dispatch->setReceivedAt($now);

        return $dispatch;
    }

    /**
     * A step in 'test' or 'paused' mode: nothing was sent to n8n, but the
     * History still says so.
     */
    public static function createSimulated(int $campaignLogId, string $mode, \DateTimeInterface $now): self
    {
        $dispatch = new self();
        $dispatch->kind          = self::KIND_DISPATCH;
        $dispatch->campaignLogId = $campaignLogId;
        $dispatch->outcome       = self::DISPATCH_PAUSED === $mode ? self::DISPATCH_PAUSED : self::DISPATCH_TEST;
        $dispatch->setReceivedAt($now);

        return $dispatch;
    }

    /**
     * Ties the entry to its campaign log and to the id it is about.
     */
    public function withLog(?int $campaignLogId, ?string $refType, ?string $refValue): self
    {
        $this->campaignLogId = $campaignLogId;
        $this->refType       = $refType;
        $this->refValue      = $refValue;

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTrackingId(): ?int
    {
        return $this->trackingId;
    }

    public function setTrackingId(?int $trackingId): self
    {
        $this->trackingId = $trackingId;

        return $this;
    }

    public function getCampaignLogId(): ?int
    {
        return $this->campaignLogId;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getRefType(): ?string
    {
        return $this->refType;
    }

    public function getRefValue(): ?string
    {
        return $this->refValue;
    }

    public function getOutcome(): string
    {
        return $this->outcome;
    }

    public function setOutcome(string $outcome): self
    {
        $this->outcome = $outcome;

        return $this;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(?string $message): self
    {
        $this->message = $message;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getBodyArray(): array
    {
        $decoded = null === $this->bodyJson ? null : json_decode($this->bodyJson, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function getReceivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function setReceivedAt(\DateTimeInterface $receivedAt): self
    {
        $this->receivedAt = \DateTimeImmutable::createFromInterface($receivedAt);

        return $this;
    }
}
