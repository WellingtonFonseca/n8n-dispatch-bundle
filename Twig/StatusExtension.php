<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Twig;

use Mautic\CoreBundle\Helper\DateTimeHelper;
use MauticPlugin\N8nDispatchBundle\Resolver\LocaleConventions;
use MauticPlugin\N8nDispatchBundle\Service\StatusTracker;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Gives the Timeline card (Resources/views/SubscribedEvents/Timeline/
 * _email_send.html.twig) the callback status of one campaign log:
 * {{ n8ndispatch_status(item.metadata) }}, its history {{ n8ndispatch_history(item.log_id) }}, and the date to print for each of
 * its entries: {{ n8ndispatch_date(entry.receivedAt) }}.
 */
class StatusExtension extends AbstractExtension
{
    public function __construct(
        private StatusTracker $tracker,
        private TranslatorInterface $translator,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('n8ndispatch_status', [$this, 'status']),
            new TwigFunction('n8ndispatch_history', [$this, 'history']),
            new TwigFunction('n8ndispatch_dispatch_text', [$this, 'dispatchText']),
            new TwigFunction('n8ndispatch_history_text', [$this, 'historyText']),
            new TwigFunction('n8ndispatch_date', [$this, 'date']),
        ];
    }

    /**
     * @param mixed $metadata the log's metadata, as the Timeline template sees it
     *
     * @return list<array<string, mixed>>
     */
    public function status(mixed $metadata): array
    {
        return is_array($metadata) ? $this->tracker->viewForMetadata($metadata) : [];
    }

    /**
     * Everything that happened to one campaign log, newest first: its
     * attempts to send and the outcomes n8n reported (see StatusTracker::historyForLog()).
     *
     * @return list<array<string, mixed>>
     */
    public function history(mixed $campaignLogId): array
    {
        return is_numeric($campaignLogId) && (int) $campaignLogId > 0 ? $this->tracker->historyForLog((int) $campaignLogId) : [];
    }

    /**
     * The text of one entry of the History: its stored JSON, written out. A
     * 'dispatch' entry keeps {"reenvio": ..., "response": ...}; a 'callback'
     * keeps {"message": <what n8n sent: text, object or list>}.
     *
     * @param array<string, mixed> $entry as StatusTracker::historyForLog() returns it
     */
    public function historyText(array $entry): string
    {
        if ('dispatch' === ($entry['kind'] ?? null)) {
            return $this->dispatchText($entry);
        }

        $message = $entry['message'] ?? null;

        if (null === $message || '' === $message) {
            return '';
        }

        return (string) json_encode(['message' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * The text of a 'dispatch' entry of the History: the JSON the entry keeps,
     * written out ({"reenvio": {"por": ..., "em": ...}, "response": ...}), with
     * the resend's moment in the system language and local time zone instead of
     * the stored UTC. Empty when there is nothing to show.
     *
     * @param array<string, mixed> $entry as StatusTracker::historyForLog() returns it
     */
    public function dispatchText(array $entry): string
    {
        $json   = [];
        $resend = $entry['resend'] ?? null;

        if (is_array($resend) && [] !== $resend) {
            if (!empty($resend['em']) && is_string($resend['em'])) {
                $when         = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $resend['em'], new \DateTimeZone('UTC'));
                $resend['em'] = false === $when ? $resend['em'] : $this->date($when);
            }

            $json['reenvio'] = $resend;
        }

        $response = $entry['response'] ?? null;

        if (null !== $response && '' !== $response) {
            $json['response'] = $response;
        }

        return [] === $json ? '' : (string) json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * A stored (UTC) date as the system language writes it, in Mautic's
     * local time zone. Mautic's own dateToFullConcat() is not used because it
     * prints the month in English whatever the language, from a fixed
     * format; the order of day/month follows LocaleConventions, the same
     * table the dispatched variables use. The language is the one the
     * translator is using for this request.
     */
    public function date(mixed $date): string
    {
        if (!$date instanceof \DateTimeInterface) {
            return '';
        }

        $locale = $this->translator instanceof LocaleAwareInterface ? $this->translator->getLocale() : LocaleConventions::DEFAULT_LOCALE;
        $utc    = \DateTimeImmutable::createFromInterface($date)->setTimezone(new \DateTimeZone('UTC'));

        // Same conversion DateHelper::toFullConcat() does: the stored value is UTC, shown in local time.
        return (new DateTimeHelper($utc->format('Y-m-d H:i:s'), 'Y-m-d H:i:s', 'UTC'))
            ->toLocalString(LocaleConventions::dateFormat($locale, 'datetime_minutes'));
    }
}
