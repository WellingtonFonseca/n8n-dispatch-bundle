<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Finds the campaigns whose "Send via n8n (Email)" steps point at an
 * Email: listed on the native Email edit page's "Campaigns" tab
 * (EventListener/EmailCampaignsTabSubscriber.php). Same mechanism as
 * Service/SmsTemplateUsageFinder.php and Service/HsmTemplateUsageFinder.php,
 * just against the 'email' properties key (Form/Type/
 * EmailDispatchActionType.php's own picker field) and the
 * 'n8ndispatch.email.send' event type — see that class's own docblock for
 * why matching happens in PHP instead of SQL.
 *
 * Unlike SMS/HSM, there's no delete-protection use for this one: Email is
 * a core entity, deleted through core's own EmailController, which this
 * plugin doesn't hook into.
 */
class EmailTemplateUsageFinder
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @return list<array{campaignId: int, campaignName: string, campaignPublished: bool, eventId: int, eventName: string, status: string}>
     */
    public function findUsages(int $emailId): array
    {
        $rows = $this->em->getConnection()->createQueryBuilder()
            ->select('e.id AS event_id, e.name AS event_name, e.properties, c.id AS campaign_id, c.name AS campaign_name, c.is_published AS campaign_published')
            ->from(MAUTIC_TABLE_PREFIX.'campaign_events', 'e')
            ->innerJoin('e', MAUTIC_TABLE_PREFIX.'campaigns', 'c', 'c.id = e.campaign_id')
            ->where('e.type = :type')
            ->andWhere('e.deleted IS NULL')
            ->andWhere('c.deleted IS NULL')
            ->orderBy('c.name')
            ->addOrderBy('e.id')
            ->setParameter('type', 'n8ndispatch.email.send')
            ->executeQuery()
            ->fetchAllAssociative();

        return self::filterRows($rows, $emailId);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return list<array{campaignId: int, campaignName: string, campaignPublished: bool, eventId: int, eventName: string, status: string}>
     */
    public static function filterRows(array $rows, int $emailId): array
    {
        $usages = [];

        foreach ($rows as $row) {
            $properties = @unserialize((string) $row['properties'], ['allowed_classes' => false]);

            if (!is_array($properties) || (int) ($properties['email'] ?? 0) !== $emailId) {
                continue;
            }

            $usages[] = [
                'campaignId'        => (int) $row['campaign_id'],
                'campaignName'      => (string) $row['campaign_name'],
                'campaignPublished' => (bool) $row['campaign_published'],
                'eventId'           => (int) $row['event_id'],
                'eventName'         => (string) $row['event_name'],
                'status'            => (string) ($properties['status'] ?? 'test'),
            ];
        }

        return $usages;
    }
}
