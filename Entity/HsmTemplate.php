<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use Mautic\CoreBundle\Entity\FormEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Mapping\ClassMetadata;

/**
 * A reusable WhatsApp HSM template reference for the "Send via n8n
 * (HSM)" Campaign Action: which WhatsApp line dispatches it ('router'),
 * the WhatsApp-side template descriptor to send ('hsmTemplate' — a
 * string, e.g. "lembrete_aula_v1"; not to be confused with
 * Form/Type/HsmDispatchActionType.php's 'hsmTemplateId', the Campaign
 * Action's own field for *which HsmTemplate entity* — this one — a
 * campaign picks, an int), and — same shape as Entity/SmsTemplate.php's
 * own 'text'/'variablesJson' — a 'text' field the user types the
 * template's copy into (with {{name}} placeholders) purely to declare
 * and map its variables, plus 'variablesJson', their source mapping.
 * 'text' is never sent anywhere: WhatsApp already has the real template
 * registered on its side, addressed by 'hsmTemplate'; it exists only so
 * the same {{name}}-scanning mechanism Email/SMS use
 * (Controller/AjaxController.php, Assets/js/campaign-hsm-dispatch.js) can
 * drive this screen's variable-source picker too, replacing the
 * positional ($1, $2, ...) picker that used to live on the Campaign
 * Action's own form and was dropped when router/hsmTemplate moved here.
 *
 * The table is created by N8nDispatchBundle::onPluginUpdate() on the
 * version bump that introduces it — this plugin was already installed
 * before this entity existed, so the install-time schema creation never
 * runs for it (same reasoning as SmsTemplate's own docblock).
 */
class HsmTemplate extends FormEntity
{
    public const TABLE_NAME = 'n8n_dispatch_hsm_templates';

    // WhatsApp HSM sends come in several shapes (text, image, carousel,
    // ...) — only 'text' is wired up for now (Form/Type/HsmTemplateType.php
    // offers no other choice yet), added ahead of the others so 'type'
    // already reaches the dispatch payload ('hsm_type') as the other
    // shapes are built out later, without another schema/payload change.
    public const TYPE_TEXT = 'text';

    private ?int $id = null;

    private ?string $name = null;

    private ?string $description = null;

    private string $type = self::TYPE_TEXT;

    private ?string $router = null;

    private ?string $hsmTemplate = null;

    private ?string $text = null;

    private ?string $variablesJson = null;

    /**
     * Same pattern as every other clonable core entity (e.g. PointBundle's
     * Trigger, EmailBundle's Email) and, closer to home, Entity/
     * SmsTemplate.php's own __clone(): FormEntity::__clone() resets the
     * publish/audit fields it owns, but knows nothing about this class's
     * own $id — left alone, `clone $entity` keeps the original row's id,
     * and Doctrine then treats the cloned, unmanaged entity as *detached*
     * rather than *new* on Save (see SmsTemplate::__clone()'s docblock for
     * the full UnitOfWork explanation), so persist() throws instead of
     * inserting.
     */
    public function __clone()
    {
        $this->id = null;

        parent::__clone();
    }

    /**
     * @param ORM\ClassMetadata<HsmTemplate> $metadata
     */
    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);

        $builder->setTable(self::TABLE_NAME)
            ->setCustomRepositoryClass(HsmTemplateRepository::class);

        // id, name, description (+ FormEntity's isPublished/created/modified
        // columns, mapped by FormEntity's own loadMetadata()).
        $builder->addIdColumns();

        // Not addNamedField(): needs the 'default' column option too, so
        // ALTER TABLE ADD COLUMN (N8nDispatchBundle::onPluginUpdate(), for
        // installs that already have this table) has a value to backfill
        // existing rows with.
        $builder->createField('type', 'string')
            ->columnName('type')
            ->option('default', self::TYPE_TEXT)
            ->build();

        $builder->addNamedField('router', 'string', 'router');
        $builder->addNamedField('hsmTemplate', 'string', 'hsm_template');
        $builder->addField('text', 'text');
        $builder->addNamedField('variablesJson', 'text', 'variables_json', true);
    }

    public static function loadValidatorMetadata(ClassMetadata $metadata): void
    {
        $metadata->addPropertyConstraint('name', new Assert\NotBlank([
            'message' => 'mautic.core.name.required',
        ]));

        $metadata->addPropertyConstraint('type', new Assert\NotBlank([
            'message' => 'mautic.core.value.required',
        ]));

        $metadata->addPropertyConstraint('router', new Assert\NotBlank([
            'message' => 'mautic.core.value.required',
        ]));

        $metadata->addPropertyConstraint('hsmTemplate', new Assert\NotBlank([
            'message' => 'mautic.core.value.required',
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

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->isChanged('type', $type);
        $this->type = $type;

        return $this;
    }

    public function getRouter(): ?string
    {
        return $this->router;
    }

    public function setRouter(?string $router): self
    {
        $this->isChanged('router', $router);
        $this->router = $router;

        return $this;
    }

    public function getHsmTemplate(): ?string
    {
        return $this->hsmTemplate;
    }

    public function setHsmTemplate(?string $hsmTemplate): self
    {
        $this->isChanged('hsmTemplate', $hsmTemplate);
        $this->hsmTemplate = $hsmTemplate;

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
