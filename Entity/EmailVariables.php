<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;

/**
 * The {{variable}} source mapping for one Mautic Email — same JSON shape
 * Form/Type/EmailDispatchActionType.php used to store inline on each
 * campaign event's own properties, moved here so it's edited once (on the
 * Email's own "Variables" tab, EventListener/EmailTabSubscriber.php) and
 * shared by every campaign that sends that Email. Same reasoning as
 * Entity/SmsTemplate.php's own variablesJson column, just keyed by an
 * Email id instead of owning the whole template — Email is a native
 * Mautic entity, so this plugin can't add a column to its table, only a
 * companion one of its own.
 *
 * Not a FormEntity: no publish state, no audit fields, no admin CRUD
 * screen of its own — written only via
 * Controller/AjaxController::saveEmailVariablesAction(), read only via
 * EmailTabSubscriber and CampaignTriggerSubscriber.
 *
 * The table is created by N8nDispatchBundle::onPluginUpdate(), same as
 * SmsTemplate/HsmTemplate's tables.
 */
class EmailVariables
{
    public const TABLE_NAME = 'n8n_dispatch_email_variables';

    private ?int $id = null;

    private int $emailId;

    private ?string $variablesJson = null;

    /**
     * @param ORM\ClassMetadata<EmailVariables> $metadata
     */
    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);

        $builder->setTable(self::TABLE_NAME)
            ->setCustomRepositoryClass(EmailVariablesRepository::class);

        $builder->addId();

        $builder->addNamedField('emailId', 'integer', 'email_id');
        $builder->addNamedField('variablesJson', 'text', 'variables_json', true);

        // Not a DB-level unique constraint — EmailVariablesRepository's
        // save() does its own findOneBy(['emailId' => ...]) load-then-write,
        // same "the caller is trusted to have looked it up first" approach
        // CustomItemWriteApiController's PUT uses (see
        // wiki/custom-objects-plugin.md's API doc) rather than fighting
        // ClassMetadataBuilder's unique-index API for a two-column table.
        $builder->addIndex(['email_id'], 'n8n_dispatch_email_variables_email_id');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmailId(): int
    {
        return $this->emailId;
    }

    public function setEmailId(int $emailId): self
    {
        $this->emailId = $emailId;

        return $this;
    }

    public function getVariablesJson(): ?string
    {
        return $this->variablesJson;
    }

    public function setVariablesJson(?string $variablesJson): self
    {
        $this->variablesJson = $variablesJson;

        return $this;
    }
}
