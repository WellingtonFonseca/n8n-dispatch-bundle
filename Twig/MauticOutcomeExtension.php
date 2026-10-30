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
     * @param mixed $leadId   the contact, when the caller has it
     * @param mixed $logId    the campaign log, to find the contact when the caller has not
     *
     * @return array<string, mixed>
     */
    public function outcome(mixed $metadata, mixed $leadId, mixed $logId): array
    {
        $metadata = is_array($metadata) ? $metadata : [];
        $lead     = is_numeric($leadId) ? (int) $leadId : 0;

        if ($lead <= 0 && is_numeric($logId) && (int) $logId > 0) {
            $lead = $this->outcome->leadOfLog((int) $logId);
        }

        return $this->outcome->detail($lead, $metadata);
    }
}
