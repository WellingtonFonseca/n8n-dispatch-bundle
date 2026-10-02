<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;

/**
 * One row per id n8n handed back after a successful dispatch — the
 * 'logSendEmailId' / 'logSendSmsId' Mirror ids, and for HSM two rows: the
 * Mirror 'logSendHsmId' and the Meta broker's 'logSendHsmUuid', each with
 * its own outcome. This is what the status poll
 * (Service/StatusPoller.php) works through: every row still 'pending'
 * is asked about, and a row leaves that queue once n8n reports
 * 'success' or 'error'.
 *
 * Tied to the dispatch by the id value itself (the same value the Timeline
 * card's log keeps in its metadata), not by the campaign log's own id —
 * that row may not be flushed yet when n8n's response arrives.
 *
 * Not a FormEntity, same reasoning as EmailVariables: no CRUD screen,
 * written only by StatusTracker. The table is created by
 * N8nDispatchBundle::onPluginUpdate().
 */
class DispatchTracking
{
    public const TABLE_NAME = 'n8n_dispatch_tracking';

    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_SMS   = 'sms';
    public const CHANNEL_HSM   = 'hsm';

    public const CHANNELS = [self::CHANNEL_EMAIL, self::CHANNEL_SMS, self::CHANNEL_HSM];

    public const REF_EMAIL    = 'logSendEmailId';
    public const REF_SMS      = 'logSendSmsId';
    public const REF_HSM_ID   = 'logSendHsmId';
    public const REF_HSM_UUID = 'logSendHsmUuid';

    /** The id keys each channel's status answers are matched by. */
    public const REFS_BY_CHANNEL = [
        self::CHANNEL_EMAIL => [self::REF_EMAIL],
        self::CHANNEL_SMS   => [self::REF_SMS],
        self::CHANNEL_HSM   => [self::REF_HSM_ID, self::REF_HSM_UUID],
    ];

    public const OUTCOME_PENDING = 'pending';
    public const OUTCOME_SUCCESS = 'success';
    public const OUTCOME_ERROR   = 'error';

    public const OUTCOMES = [self::OUTCOME_PENDING, self::OUTCOME_SUCCESS, self::OUTCOME_ERROR];

    private ?int $id = null;

    private string $channel;

    private string $refType;

    private string $refValue;

    /** Shared by the rows of one dispatch (the two HSM ids), so the poll can ask about them in one item. */
    private string $groupKey;

    private string $outcome = self::OUTCOME_PENDING;

    private ?string $message = null;

    private \DateTimeImmutable $dispatchedAt;

    private ?\DateTimeImmutable $lastCheckedAt = null;

    private int $checkCount = 0;

    private ?\DateTimeImmutable $changedAt = null;

    /**
     * @param ORM\ClassMetadata<DispatchTracking> $metadata
     */
    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);

        $builder->setTable(self::TABLE_NAME)
            ->setCustomRepositoryClass(DispatchTrackingRepository::class);

        $builder->addId();

        $builder->addNamedField('channel', 'string', 'channel', false);
        $builder->addNamedField('refType', 'string', 'ref_type', false);
        // 191 keeps the unique index below inside utf8mb4's index length limit.
        $builder->createField('refValue', 'string')->columnName('ref_value')->length(191)->build();
        $builder->addNamedField('groupKey', 'string', 'group_key', false);
        $builder->addNamedField('outcome', 'string', 'outcome', false);
        $builder->addNamedField('message', 'text', 'message', true);
        $builder->addNamedField('dispatchedAt', 'datetime_immutable', 'dispatched_at', false);
        $builder->addNamedField('lastCheckedAt', 'datetime_immutable', 'last_checked_at', true);
        $builder->addNamedField('checkCount', 'integer', 'check_count', false);
        $builder->addNamedField('changedAt', 'datetime_immutable', 'changed_at', true);

        $builder->addUniqueConstraint(['ref_type', 'ref_value'], 'n8n_dispatch_tracking_ref');
        $builder->addIndex(['channel', 'outcome', 'last_checked_at'], 'n8n_dispatch_tracking_pending');
    }

    public static function create(string $channel, string $refType, string $refValue, string $groupKey, \DateTimeInterface $now): self
    {
        $tracking               = new self();
        $tracking->channel      = $channel;
        $tracking->refType      = $refType;
        $tracking->refValue     = $refValue;
        $tracking->groupKey     = $groupKey;
        $tracking->dispatchedAt = \DateTimeImmutable::createFromInterface($now);

        return $tracking;
    }

    /**
     * Records one answer from n8n. Returns true only when the outcome
     * changed — that is the one case worth a history row; an unchanged
     * answer (a still-pending id asked about again) just counts the check.
     * A new message under the same outcome replaces the stored one without
     * counting as a change, and a missing message keeps the previous one;
     * on a change the message is replaced outright.
     */
    public function applyOutcome(string $outcome, ?string $message, \DateTimeInterface $now): bool
    {
        if (!in_array($outcome, self::OUTCOMES, true)) {
            throw new \InvalidArgumentException('Unknown outcome "'.$outcome.'".');
        }

        $now = \DateTimeImmutable::createFromInterface($now);

        ++$this->checkCount;
        $this->lastCheckedAt = $now;

        if ($outcome === $this->outcome) {
            if (null !== $message) {
                $this->message = $message;
            }

            return false;
        }

        // The message belongs to the outcome it came with: a change takes
        // the new answer's message, even none (an old error reason must
        // not stay under a success — it is still in the history).
        $this->outcome   = $outcome;
        $this->message   = $message;
        $this->changedAt = $now;

        return true;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getChannel(): string
    {
        return $this->channel;
    }

    public function getRefType(): string
    {
        return $this->refType;
    }

    public function getRefValue(): string
    {
        return $this->refValue;
    }

    public function getGroupKey(): string
    {
        return $this->groupKey;
    }

    public function getOutcome(): string
    {
        return $this->outcome;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function getDispatchedAt(): \DateTimeImmutable
    {
        return $this->dispatchedAt;
    }

    public function getLastCheckedAt(): ?\DateTimeImmutable
    {
        return $this->lastCheckedAt;
    }

    public function getCheckCount(): int
    {
        return $this->checkCount;
    }

    public function getChangedAt(): ?\DateTimeImmutable
    {
        return $this->changedAt;
    }
}
