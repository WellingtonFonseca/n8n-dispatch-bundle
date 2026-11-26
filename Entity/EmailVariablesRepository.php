<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * @extends CommonRepository<EmailVariables>
 */
class EmailVariablesRepository extends CommonRepository
{
    public function getTableAlias(): string
    {
        return 'env';
    }

    /**
     * Upserts the row for one Email id — findOneBy-then-write, same
     * pattern as the rest of this plugin's write paths (see Entity/
     * EmailVariables.php's own docblock on why there's no DB-level
     * unique constraint backing this instead).
     */
    public function saveForEmail(int $emailId, string $variablesJson): void
    {
        $entity = $this->findOneBy(['emailId' => $emailId]);

        if (null === $entity) {
            $entity = new EmailVariables();
            $entity->setEmailId($emailId);
        }

        $entity->setVariablesJson($variablesJson);

        $this->saveEntity($entity);
    }

    /**
     * Stores the "Enviar por" of one Email, keeping its variables as they are.
     */
    public function saveProviderForEmail(int $emailId, string $provider): void
    {
        $entity = $this->findOneBy(['emailId' => $emailId]);

        if (null === $entity) {
            $entity = new EmailVariables();
            $entity->setEmailId($emailId);
        }

        $entity->setProvider($provider);

        $this->saveEntity($entity);
    }

    /**
     * An Email with no row yet is sent through n8n, as before.
     */
    public function getProviderForEmail(int $emailId): string
    {
        $provider = $this->findOneBy(['emailId' => $emailId])?->getProvider();

        return EmailVariables::PROVIDER_MAUTIC === $provider ? EmailVariables::PROVIDER_MAUTIC : EmailVariables::PROVIDER_N8N;
    }

    /**
     * Null means "no row for this Email at all" (its "Variables" tab was
     * never opened/saved) — distinct from a row that exists but was
     * deliberately cleared to '{}'. CampaignTriggerSubscriber uses that
     * distinction to know when to fall back to a pre-existing event's own
     * inline properties.variablesJson instead.
     */
    public function getVariablesJsonForEmail(int $emailId): ?string
    {
        $entity = $this->findOneBy(['emailId' => $emailId]);

        return $entity?->getVariablesJson();
    }
}
