<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

use MauticPlugin\N8nDispatchBundle\Entity\DispatchTracking;

/**
 * What the Dispatches screen's filter bar asks for, read from the query
 * string: campaign, template ("<channel>:<id>"), a date range (whole days in
 * Mautic's time zone, held as UTC like the logs are) and the status shown in
 * the callback column. Anything unknown or malformed is simply not a filter.
 */
final class DispatchFilters
{
    public const STATUS_ERROR   = 'error';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_PENDING = 'pending';
    public const STATUS_FAILED  = 'failed';

    public const STATUSES = [self::STATUS_ERROR, self::STATUS_SUCCESS, self::STATUS_PENDING, self::STATUS_FAILED];

    /**
     * @param array{0: string, 1: int}|null $template channel and template id
     */
    public function __construct(
        public readonly ?int $campaignId = null,
        public readonly ?array $template = null,
        public readonly ?\DateTimeImmutable $from = null,
        public readonly ?\DateTimeImmutable $to = null,
        public readonly ?string $status = null,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     * @param string               $dateFormat how the user types dates (the system language's: d/m/Y, m/d/Y, ...)
     */
    public static function fromQuery(array $query, \DateTimeZone $timeZone, string $dateFormat = 'Y-m-d'): self
    {
        $campaign = (int) ($query['campaign'] ?? 0);

        $template = null;
        if (is_string($query['template'] ?? null) && 1 === preg_match('/^(email|sms|hsm):(\d+)$/', $query['template'], $m) && (int) $m[2] > 0) {
            $template = [$m[1], (int) $m[2]];
        }

        $status = $query['status'] ?? null;

        return new self(
            $campaign > 0 ? $campaign : null,
            $template,
            self::dayStart($query['from'] ?? null, $timeZone, $dateFormat),
            self::dayEnd($query['to'] ?? null, $timeZone, $dateFormat),
            is_string($status) && in_array($status, self::STATUSES, true) ? $status : null,
        );
    }

    public function isEmpty(): bool
    {
        return null === $this->campaignId && null === $this->template && null === $this->from && null === $this->to && null === $this->status;
    }

    /**
     * The same filters as query-string parameters (for the pagination links),
     * dates in the format the user types them.
     *
     * @return array<string, string|int>
     */
    public function toQuery(\DateTimeZone $timeZone, string $dateFormat = 'Y-m-d'): array
    {
        return array_filter([
            'campaign' => $this->campaignId,
            'template' => null !== $this->template ? $this->template[0].':'.$this->template[1] : null,
            'from'     => $this->from?->setTimezone($timeZone)->format($dateFormat),
            'to'       => $this->to?->setTimezone($timeZone)->format($dateFormat),
            'status'   => $this->status,
        ], static fn ($value): bool => null !== $value);
    }

    /**
     * Which ref type of a log holds the callback the screen shows (HSM: Meta's).
     */
    public static function shownRefType(string $channel): ?string
    {
        return match ($channel) {
            DispatchTracking::CHANNEL_EMAIL => DispatchTracking::REF_EMAIL,
            DispatchTracking::CHANNEL_SMS   => DispatchTracking::REF_SMS,
            DispatchTracking::CHANNEL_HSM   => DispatchTracking::REF_HSM_UUID,
            default                         => null,
        };
    }

    /**
     * The status the callback column shows for a log: 'failed' when the call
     * itself was refused, else the outcome of the shown callback; null when
     * there is none.
     *
     * @param array<string, mixed>  $metadata
     * @param array<string, string> $outcomes "<refType>|<refValue>" => outcome
     */
    public static function statusOf(string $channel, array $metadata, array $outcomes): ?string
    {
        if (DispatchResender::isFailed($metadata)) {
            return self::STATUS_FAILED;
        }

        $refType = self::shownRefType($channel);
        $value   = null === $refType ? null : ($metadata[$refType] ?? null);

        if (null === $refType || !(is_int($value) || is_string($value)) || '' === (string) $value) {
            return null;
        }

        return $outcomes[$refType.'|'.$value] ?? null;
    }

    private static function dayStart(mixed $value, \DateTimeZone $timeZone, string $format): ?\DateTimeImmutable
    {
        return self::day($value, $timeZone, $format)?->setTime(0, 0, 0)->setTimezone(new \DateTimeZone('UTC'));
    }

    private static function dayEnd(mixed $value, \DateTimeZone $timeZone, string $format): ?\DateTimeImmutable
    {
        return self::day($value, $timeZone, $format)?->setTime(23, 59, 59)->setTimezone(new \DateTimeZone('UTC'));
    }

    private static function day(mixed $value, \DateTimeZone $timeZone, string $format): ?\DateTimeImmutable
    {
        if (!is_string($value)) {
            return null;
        }

        $day = \DateTimeImmutable::createFromFormat('!'.$format, $value, $timeZone);

        // Strict: "31/02/2026" must not roll over to March.
        return false === $day || $day->format($format) !== $value ? null : $day;
    }
}
