<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

use Doctrine\DBAL\ArrayParameterType;

/**
 * Finds the campaigns whose "Send via n8n (Email)" steps point at an
 * Email: listed on the native Email edit page's "Campaigns" tab
 * (EventListener/EmailCampaignsTabSubscriber.php). The mechanism is in
 * Service/AbstractTemplateUsageFinder.php; here only the 'email' properties
 * key (Form/Type/EmailDispatchActionType.php's own picker field) and the
 * 'n8ndispatch.email.send' event type.
 *
 * Email is a core entity, deleted through core's own controllers, so the
 * delete protection is EventListener/EmailDeleteGuardSubscriber (SMS and HSM
 * check it in their own controllers).
 */
class EmailTemplateUsageFinder extends AbstractTemplateUsageFinder
{
    protected const EVENT_TYPE   = 'n8ndispatch.email.send';
    protected const PROPERTY_KEY = 'email';

    /**
     * Deleting an Email also deletes its translations and A/B variants (the foreign keys cascade, at any depth), so
     * an Email is in use when a step uses it OR any Email below it.
     *
     * @param list<int> $templateIds
     *
     * @return list<int>
     */
    public function inUseAmong(array $templateIds): array
    {
        if ([] === $templateIds) {
            return [];
        }

        $familyOf = $this->descendantsOf($templateIds);
        $inUse    = parent::inUseAmong(array_values(array_unique(array_merge(...array_values($familyOf)))));

        return array_values(array_filter($templateIds, static fn (int $id): bool => [] !== array_intersect($familyOf[$id], $inUse)));
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, list<int>> each id with every Email below it, itself included
     */
    private function descendantsOf(array $ids): array
    {
        $connection = $this->em->getConnection();
        $table      = MAUTIC_TABLE_PREFIX.'emails';
        $family     = array_combine($ids, array_map(static fn (int $id): array => [$id], $ids));
        // What each id still has to be asked about: id => the Emails found at the last level.
        $frontier = array_combine($ids, array_map(static fn (int $id): array => [$id], $ids));

        while ([] !== $frontier) {
            $parents = array_values(array_unique(array_merge(...array_values($frontier))));
            $rows    = $connection->fetchAllAssociative(
                'SELECT id, translation_parent_id, variant_parent_id FROM '.$table.' WHERE translation_parent_id IN (?) OR variant_parent_id IN (?)',
                [$parents, $parents],
                [ArrayParameterType::INTEGER, ArrayParameterType::INTEGER]
            );
            $children = [];

            foreach ($rows as $row) {
                foreach (['translation_parent_id', 'variant_parent_id'] as $column) {
                    $children[(int) $row[$column]][] = (int) $row['id'];
                }
            }

            $next = [];

            foreach ($frontier as $root => $found) {
                foreach ($found as $parent) {
                    foreach ($children[$parent] ?? [] as $child) {
                        if (!in_array($child, $family[$root], true)) {
                            $family[$root][] = $child;
                            $next[$root][]   = $child;
                        }
                    }
                }
            }

            $frontier = $next;
        }

        return $family;
    }
}
