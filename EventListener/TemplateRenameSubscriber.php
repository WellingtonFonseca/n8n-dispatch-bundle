<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\EmailBundle\Entity\Email;
use MauticPlugin\N8nDispatchBundle\Entity\HsmTemplate;
use MauticPlugin\N8nDispatchBundle\Entity\SmsTemplate;
use MauticPlugin\N8nDispatchBundle\Service\AbstractTemplateUsageFinder;
use MauticPlugin\N8nDispatchBundle\Service\EmailTemplateUsageFinder;
use MauticPlugin\N8nDispatchBundle\Service\EventNameSync;
use MauticPlugin\N8nDispatchBundle\Service\HsmTemplateUsageFinder;
use MauticPlugin\N8nDispatchBundle\Service\SmsTemplateUsageFinder;

/**
 * When an Email, an SMS template or an HSM template is renamed, the campaign actions that dispatch it take the new
 * name (the name of an action is always its template's, see EventNameSync). A Doctrine listener, not a form event:
 * the SMS and HSM models dispatch nothing of their own, and this also covers the API and imports.
 */
#[AsDoctrineListener(Events::preUpdate)]
class TemplateRenameSubscriber
{
    public function __construct(
        private EmailTemplateUsageFinder $emails,
        private SmsTemplateUsageFinder $smsTemplates,
        private HsmTemplateUsageFinder $hsmTemplates,
        private EntityManagerInterface $em,
    ) {
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        $finder = match (true) {
            $entity instanceof Email       => $this->emails,
            $entity instanceof SmsTemplate => $this->smsTemplates,
            $entity instanceof HsmTemplate => $this->hsmTemplates,
            default                        => null,
        };

        if (null === $finder || !$args->hasChangedField('name')) {
            return;
        }

        $this->rename($finder, (int) $entity->getId(), (string) $args->getNewValue('name'));
    }

    private function rename(AbstractTemplateUsageFinder $finder, int $templateId, string $name): void
    {
        $name = EventNameSync::fit($name);
        if ('' === $name) {
            return;
        }

        $ids = array_column($finder->findUsages($templateId), 'eventId');
        if ([] === $ids) {
            return;
        }

        $table = $this->em->getClassMetadata(Event::class)->getTableName();

        $this->em->getConnection()->executeStatement(
            sprintf('UPDATE %s SET name = ? WHERE id IN (%s)', $table, implode(',', $ids)),
            [$name]
        );
    }
}
