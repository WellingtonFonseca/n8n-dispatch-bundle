<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service\Alert;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\LeadBundle\Entity\DoNotContact;

/**
 * The published campaigns whose Email steps bounced too much lately: contacts that got an Email step in the window and
 * went on the Do Not Contact list of email as a bounce in the window, as a share of the contacts that got one (a step
 * that core logged as failed did not reach the contact and does not count). It reads
 * Mautic's own tables, so it works the same when the Email goes out through n8n or through Mautic's mailer.
 */
class BounceSpikeReader
{
    private const EMAIL_STEP = 'n8ndispatch.email.send';

    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * @return list<array{campaign_id: int, name: string, sends: int, bounces: int, percent: float}>
     */
    public function offenders(\DateTimeImmutable $since, int $minSends, int $percent): array
    {
        $prefix = MAUTIC_TABLE_PREFIX;
        $sql    = 'SELECT c.id AS campaign_id, c.name, COUNT(DISTINCT l.lead_id) AS sends, COUNT(DISTINCT d.lead_id) AS bounces'
            .' FROM '.$prefix.'campaign_lead_event_log l'
            .' JOIN '.$prefix.'campaign_events e ON e.id = l.event_id AND e.type = :type'
            .' JOIN '.$prefix.'campaigns c ON c.id = e.campaign_id AND c.is_published = 1'
            .' LEFT JOIN '.$prefix.'campaign_lead_event_failed_log f ON f.log_id = l.id'
            .' LEFT JOIN '.$prefix.'lead_donotcontact d ON d.lead_id = l.lead_id AND d.channel = :channel AND d.reason = :reason AND d.date_added >= :since'
            .' WHERE l.date_triggered >= :since AND l.is_scheduled = 0 AND l.non_action_path_taken = 0 AND f.log_id IS NULL'
            .' GROUP BY c.id, c.name'
            .' HAVING COUNT(DISTINCT l.lead_id) >= :minSends AND COUNT(DISTINCT d.lead_id) * 100 >= :percent * COUNT(DISTINCT l.lead_id)';

        $rows = $this->em->getConnection()->executeQuery($sql, [
            'type'     => self::EMAIL_STEP,
            'channel'  => 'email',
            'reason'   => DoNotContact::BOUNCED,
            // Mautic stores every date in UTC, whatever the timezone of the settings
            // Mautic stores every date in UTC, whatever the timezone of the settings
            'since'    => $since->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'minSends' => $minSends,
            'percent'  => $percent,
        ])->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'campaign_id' => (int) $row['campaign_id'],
            'name'        => (string) $row['name'],
            'sends'       => (int) $row['sends'],
            'bounces'     => (int) $row['bounces'],
            'percent'     => round((int) $row['bounces'] / (int) $row['sends'] * 100, 1),
        ], $rows);
    }
}
