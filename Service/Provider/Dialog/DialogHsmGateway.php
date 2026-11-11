<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service\Provider\Dialog;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\IntegrationsBundle\Exception\IntegrationNotFoundException;
use Mautic\IntegrationsBundle\Helper\IntegrationsHelper;
use MauticPlugin\DialogHSMBundle\Entity\WhatsAppNumber;
use MauticPlugin\DialogHSMBundle\Integration\DialogHSMIntegration;
use MauticPlugin\N8nDispatchBundle\Service\Provider\HsmProviderGateway;
use Psr\Log\LoggerInterface;

/**
 * The gateway of an instance that has the DialogHSM plugin. It is the only class of this plugin that names that
 * plugin's classes, and Config/services.php registers it only where they exist (the folder is also kept out of the
 * automatic service loading): on any other instance it is never read, never autoloaded.
 *
 * "Available" is what the DialogHSM itself asks before it sends: its integration is published. The numbers are the
 * published ones of its own table, read through Doctrine, so the DialogHSM keeps owning that data.
 */
final class DialogHsmGateway implements HsmProviderGateway
{
    private ?bool $available = null;

    public function __construct(
        private readonly IntegrationsHelper $integrations,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->available ??= $this->integrationIsPublished();
    }

    public function numbers(): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        $numbers = [];

        foreach ($this->entityManager->getRepository(WhatsAppNumber::class)->findBy(['isPublished' => true], ['name' => 'ASC']) as $number) {
            $numbers[(int) $number->getId()] = (string) $number->getName();
        }

        return $numbers;
    }

    public function numberName(int $id): ?string
    {
        if (!$this->isAvailable()) {
            return null;
        }

        $number = $this->entityManager->getRepository(WhatsAppNumber::class)->find($id);

        return null !== $number && $number->getIsPublished() ? (string) $number->getName() : null;
    }

    /**
     * A container built before the plugin was installed does not know its integration yet: that is "not available"
     * (cache:clear fixes it), logged so the cause is visible.
     */
    private function integrationIsPublished(): bool
    {
        try {
            return $this->integrations->getIntegration(DialogHSMIntegration::NAME)->getIntegrationConfiguration()->getIsPublished();
        } catch (IntegrationNotFoundException $e) {
            $this->logger->warning('N8N Dispatch: the DialogHSM integration is not known to this container; run cache:clear.', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
