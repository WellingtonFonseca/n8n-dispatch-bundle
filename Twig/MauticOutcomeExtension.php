<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Twig;

use MauticPlugin\N8nDispatchBundle\Service\MauticEmailOutcome;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * What Mautic knows about an Email that went out through its own mailer, for the dispatch card (the contact's
 * Timeline and the Dispatches screen's details): see Service/MauticEmailOutcome.php.
 */
class MauticOutcomeExtension extends AbstractExtension
{
    public function __construct(private MauticEmailOutcome $outcome)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('n8ndispatch_is_direct', [$this, 'isDirect']),
            new TwigFunction('n8ndispatch_mautic_outcome', [$this, 'outcome']),
        ];
    }

    /**
     * Whether the dispatch of this log went out through Mautic's mailer, not n8n.
     */
    public function isDirect(mixed $metadata): bool
    {
        return is_array($metadata) && MauticEmailOutcome::isDirect($metadata);
    }

    /**
     * @param mixed $metadata the log's metadata, as the card sees it
     * @param mixed $leadId   the contact, when the caller has it and no log
     * @param mixed $logId    the campaign log: it gives the contact, the step and the moment
     *
     * @return array<string, mixed>
     */
    public function outcome(mixed $metadata, mixed $leadId, mixed $logId): array
    {
        $metadata = is_array($metadata) ? $metadata : [];

        // The log knows the contact, the step and the moment; the Stat of an Email Mautic held back is found by them.
        if (is_numeric($logId) && (int) $logId > 0) {
            $facts = $this->outcome->factsOfLog((int) $logId);

            return $this->outcome->detail($facts['lead'] > 0 ? $facts['lead'] : (is_numeric($leadId) ? (int) $leadId : 0), $metadata, $facts['event'], $facts['since']);
        }

        return $this->outcome->detail(is_numeric($leadId) ? (int) $leadId : 0, $metadata);
    }
}
