<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * @extends CommonRepository<SmsTemplate>
 */
class SmsTemplateRepository extends CommonRepository
{
    public function getTableAlias(): string
    {
        return 'st';
    }

    public function getEntities(array $args = [])
    {
        // Without qb it returns entities indexed by id instead of array
        // indexes (same workaround as core's PointBundle GroupRepository).
        $args['qb'] = $this->createQueryBuilder($this->getTableAlias());

        return parent::getEntities($args);
    }

    /**
     * Choices for the "Send via n8n (SMS)" Campaign Action's template
     * picker: published templates only, as name => id.
     *
     * @return array<string, int>
     */
    public function getPublishedChoices(): array
    {
        $rows = $this->createQueryBuilder('st')
            ->select('st.id, st.name')
            ->where('st.isPublished = :published')
            ->setParameter('published', true)
            ->orderBy('st.name', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $choices = [];
        foreach ($rows as $row) {
            $choices[$row['name'].' (#'.$row['id'].')'] = (int) $row['id'];
        }

        return $choices;
    }
}
