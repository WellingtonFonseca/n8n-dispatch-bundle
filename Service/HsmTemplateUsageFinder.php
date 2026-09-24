<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Finds the campaigns whose "Send via n8n (HSM)" steps point at an
 * HsmTemplate: listed on the template's edit page, and used to refuse
 * deleting a template that is still in use. Same mechanism as Service/
 * SmsTemplateUsageFinder.php, just against the 'hsmTemplate' properties
 * key and the 'n8ndispatch.hsm.send' event type — see that class's own
 * docblock for why matching happens in PHP instead of SQL.
 */
class HsmTemplateUsageFinder
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @return list<array{campaignId: int, campaignName: string, campaignPublished: bool, eventId: int, eventName: string, status: string}>
     */
    public function findUsages(int $templateId): array
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
            ->setParameter('type', 'n8ndispatch.hsm.send')
            ->executeQuery()
            ->fetchAllAssociative();

        return self::filterRows($rows, $templateId);
    }

    public function isInUse(int $templateId): bool
    {
        return [] !== $this->findUsages($templateId);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return list<array{campaignId: int, campaignName: string, campaignPublished: bool, eventId: int, eventName: string, status: string}>
     */
    public static function filterRows(array $rows, int $templateId): array
    {
        $usages = [];

        foreach ($rows as $row) {
            $properties = @unserialize((string) $row['properties'], ['allowed_classes' => false]);

            if (!is_array($properties) || (int) ($properties['hsmTemplate'] ?? 0) !== $templateId) {
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
