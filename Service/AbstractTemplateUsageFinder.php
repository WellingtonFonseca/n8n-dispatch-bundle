<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Finds the campaigns whose "Send via n8n" steps point at a template (an
 * Email, an SMS template or an HSM template): listed on the template's page,
 * and used to refuse deleting a template that is still in use. A subclass
 * says only which event type it looks at and which key of the step's
 * properties holds the template id.
 *
 * The template id lives inside the campaign event's serialized 'properties'
 * column, which can't be matched reliably in SQL, so every non-deleted step
 * of the type is loaded and matched in PHP — a handful of rows in practice.
 */
abstract class AbstractTemplateUsageFinder
{
    /** Type of the campaign event that sends with this kind of template. */
    protected const EVENT_TYPE = '';

    /** Key of the step's properties that holds the template id (see the Form/Type/*DispatchActionType.php). */
    protected const PROPERTY_KEY = '';

    public function __construct(
        protected EntityManagerInterface $em,
    ) {
    }

    /**
     * @return list<array{campaignId: int, campaignName: string, campaignPublished: bool, eventId: int, eventName: string, status: string}>
     */
    public function findUsages(int $templateId): array
    {
        return static::filterRows($this->loadRows(), $templateId);
    }

    public function isInUse(int $templateId): bool
    {
        return [] !== $this->findUsages($templateId);
    }

    /**
     * Of the given template ids, those a step still uses; one query for the lot.
     *
     * @param list<int> $templateIds
     *
     * @return list<int>
     */
    public function inUseAmong(array $templateIds): array
    {
        $rows = $this->loadRows();

        return array_values(array_filter($templateIds, static fn (int $id): bool => [] !== static::filterRows($rows, $id)));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadRows(): array
    {
        return $this->em->getConnection()->createQueryBuilder()
            ->select('e.id AS event_id, e.name AS event_name, e.properties, c.id AS campaign_id, c.name AS campaign_name, c.is_published AS campaign_published')
            ->from(MAUTIC_TABLE_PREFIX.'campaign_events', 'e')
            ->innerJoin('e', MAUTIC_TABLE_PREFIX.'campaigns', 'c', 'c.id = e.campaign_id')
            ->where('e.type = :type')
            ->andWhere('e.deleted IS NULL')
            ->andWhere('c.deleted IS NULL')
            ->orderBy('c.name')
            ->addOrderBy('e.id')
            ->setParameter('type', static::EVENT_TYPE)
            ->executeQuery()
            ->fetchAllAssociative();
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

            if (!is_array($properties) || (int) ($properties[static::PROPERTY_KEY] ?? 0) !== $templateId) {
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
