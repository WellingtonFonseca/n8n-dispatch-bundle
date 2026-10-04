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
