<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;

/**
 * The history of one DispatchTracking row: one row each time n8n reported
 * an outcome different from the previous one (so an id that stays
 * 'pending' for hours adds nothing here — see DispatchTracking::applyOutcome()).
 * Nothing is ever overwritten, so a later 'success' still leaves the earlier
 * 'error' visible on the Timeline card.
 *
 * The table is created by N8nDispatchBundle::onPluginUpdate().
 */
class DispatchCallback
{
    public const TABLE_NAME = 'n8n_dispatch_callback';

    private ?int $id = null;

    private int $trackingId;

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

        $builder->addNamedField('trackingId', 'integer', 'tracking_id', false);
        $builder->addNamedField('outcome', 'string', 'outcome', false);
        $builder->addNamedField('message', 'text', 'message', true);
        $builder->addNamedField('bodyJson', 'text', 'body_json', true);
        $builder->addNamedField('receivedAt', 'datetime_immutable', 'received_at', false);

        $builder->addIndex(['tracking_id'], 'n8n_dispatch_callback_tracking');
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

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTrackingId(): int
    {
        return $this->trackingId;
    }

    public function setTrackingId(int $trackingId): self
    {
        $this->trackingId = $trackingId;

        return $this;
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
