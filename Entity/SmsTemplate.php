<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use Mautic\CoreBundle\Entity\FormEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Mapping\ClassMetadata;

/**
 * A reusable SMS message for the "Send via n8n (SMS)" Campaign Action:
 * the text plus its {{variable}} source mapping, the same two things
 * that action used to store inline on each campaign event. Moving them
 * here lets several campaigns point at one template, so editing the
 * message or a variable's source is done once instead of once per
 * campaign. 'variablesJson' keeps the exact same JSON shape documented
 * on Form/Type/EmailDispatchActionType.php, so VariableResolver reads it
 * unchanged.
 *
 * The table is created by N8nDispatchBundle::onPluginUpdate() — this
 * plugin was already installed before this entity existed, so the
 * install-time schema creation never runs for it.
 */
class SmsTemplate extends FormEntity
{
    public const TABLE_NAME = 'n8n_dispatch_sms_templates';

    private ?int $id = null;

    private ?string $name = null;

    private ?string $description = null;

    private ?string $text = null;

    private ?string $variablesJson = null;

    /**
     * Same pattern as every other clonable core entity (e.g. PointBundle's
     * Trigger, EmailBundle's Email): FormEntity::__clone() resets the
     * publish/audit fields it owns, but knows nothing about this class's
     * own $id — left alone, `clone $entity` keeps the original row's id.
     * Doctrine then sees a non-empty id on an unmanaged entity and treats
     * it as *detached* rather than *new* (its id generator isn't
     * "natural" — see UnitOfWork::getEntityState()), so persist() on Save
     * throws instead of inserting a new row: Clone opens the form fine,
     * but Save silently does nothing.
     */
    public function __clone()
    {
        $this->id = null;

        parent::__clone();
    }

    /**
     * @param ORM\ClassMetadata<SmsTemplate> $metadata
     */
    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);

        $builder->setTable(self::TABLE_NAME)
            ->setCustomRepositoryClass(SmsTemplateRepository::class);

        // id, name, description (+ FormEntity's isPublished/created/modified
        // columns, mapped by FormEntity's own loadMetadata()).
        $builder->addIdColumns();

        $builder->addField('text', 'text');

        $builder->addNamedField('variablesJson', 'text', 'variables_json', true);
    }

    public static function loadValidatorMetadata(ClassMetadata $metadata): void
    {
        $metadata->addPropertyConstraint('name', new Assert\NotBlank([
            'message' => 'mautic.core.name.required',
        ]));

        $metadata->addPropertyConstraint('text', new Assert\NotBlank([
            'message' => 'mautic.core.value.required',
        ]));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->isChanged('name', $name);
        $this->name = $name;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->isChanged('description', $description);
        $this->description = $description;

        return $this;
    }

    public function getText(): ?string
    {
        return $this->text;
    }

    public function setText(?string $text): self
    {
        $this->isChanged('text', $text);
        $this->text = $text;

        return $this;
    }

    public function getVariablesJson(): ?string
    {
        return $this->variablesJson;
    }

    public function setVariablesJson(?string $variablesJson): self
    {
        $this->isChanged('variablesJson', $variablesJson);
        $this->variablesJson = $variablesJson;

        return $this;
    }
}
