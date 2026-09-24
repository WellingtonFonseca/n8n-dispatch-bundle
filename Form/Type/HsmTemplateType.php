<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Form\Type;

use Mautic\CoreBundle\Form\EventListener\CleanFormSubscriber;
use Mautic\CoreBundle\Form\EventListener\FormExitSubscriber;
use Mautic\CoreBundle\Form\Type\FormButtonsType;
use Mautic\CoreBundle\Form\Type\YesNoButtonGroupType;
use MauticPlugin\N8nDispatchBundle\Entity\HsmTemplate;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Create/edit form for an HsmTemplate. 'router' and 'hsmTemplate' are the
 * same two plain fields the "Send via n8n (HSM)" Campaign Action form
 * used to carry itself (Form/Type/HsmDispatchActionType.php) — moved
 * here so one edit reaches every campaign using the template, same
 * reasoning as Form/Type/SmsTemplateType.php's own move. UI field order
 * (both here and in the twig template) is 'router', 'hsmTemplate',
 * 'type' — this is about the on-screen order only; the dispatch payload
 * sent to n8n (EventListener/HsmCampaignTriggerSubscriber.php) has its
 * own key order, unrelated. 'hsmTemplate' is labeled "HSM Template"
 * (mautic.n8ndispatch.campaign.event.hsm.template) — not to be confused
 * with Form/Type/HsmDispatchActionType.php's 'hsmTemplateId'
 * (mautic.n8ndispatch.campaign.event.hsm.template_id), the Campaign
 * Action's own field for *which HsmTemplate entity* a campaign picks (an
 * int); this one is the raw WhatsApp-side template identifier string,
 * typed here on the template entity itself.
 *
 * 'text' and 'variablesJson' are wired exactly like Entity/SmsTemplate's
 * own pair (same JS callbacks/hidden-field class, just the Hsm-named
 * counterparts — Assets/js/campaign-hsm-dispatch.js), even though 'text'
 * is never sent anywhere: WhatsApp already has the real template
 * registered by 'hsmTemplate'. It exists purely so the same
 * {{name}}-scanning mechanism Email/SMS use can drive this screen's
 * variable-source picker too, replacing the positional ($1, $2, ...)
 * picker this Campaign Action form used to have.
 *
 * @extends AbstractType<HsmTemplate>
 */
class HsmTemplateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // 'text' and 'variablesJson' are kept 'raw', same reasoning as
        // Form/Type/SmsTemplateType.php's own CleanFormSubscriber config.
        $builder->addEventSubscriber(new CleanFormSubscriber([
            'description'   => 'html',
            'text'          => 'raw',
            'variablesJson' => 'raw',
        ]));
        $builder->addEventSubscriber(new FormExitSubscriber('n8ndispatch.hsmtemplate', $options));

        $builder->add('name', TextType::class, [
            'label'      => 'mautic.core.name',
            'label_attr' => ['class' => 'control-label'],
            'attr'       => ['class' => 'form-control'],
        ]);

        $builder->add('description', TextareaType::class, [
            'label'      => 'mautic.core.description',
            'label_attr' => ['class' => 'control-label'],
            'attr'       => ['class' => 'form-control'],
            'required'   => false,
        ]);

        $builder->add('router', TextType::class, [
            'label'      => 'mautic.n8ndispatch.campaign.event.hsm.router',
            'label_attr' => ['class' => 'control-label'],
            'attr'       => ['class' => 'form-control'],
            'constraints' => [
                new NotBlank(['message' => 'mautic.core.value.required']),
            ],
        ]);

        $builder->add('hsmTemplate', TextType::class, [
            'label'      => 'mautic.n8ndispatch.campaign.event.hsm.template',
            'label_attr' => ['class' => 'control-label'],
            'attr'       => ['class' => 'form-control'],
            'constraints' => [
                new NotBlank(['message' => 'mautic.core.value.required']),
            ],
        ]);

        // WhatsApp HSM sends come in several shapes (text, image, carousel,
        // ...) — only 'text' is wired up so far; see Entity/HsmTemplate.php's
        // own TYPE_TEXT docblock for why the field exists ahead of the rest.
        // Placed last on the screen, after router/hsmTemplate: on request.
        $builder->add('type', ChoiceType::class, [
            'label'      => 'mautic.n8ndispatch.campaign.event.hsm.type',
            'label_attr' => ['class' => 'control-label'],
            'choices'    => [
                'mautic.n8ndispatch.hsmtemplate.type.text' => HsmTemplate::TYPE_TEXT,
            ],
            'attr' => ['class' => 'form-control'],
            'constraints' => [
                new NotBlank(['message' => 'mautic.core.value.required']),
            ],
        ]);

        $builder->add('text', TextareaType::class, [
            'label'      => 'mautic.n8ndispatch.campaign.event.hsm.text',
            'label_attr' => ['class' => 'control-label'],
            'attr'       => [
                'class'                => 'form-control',
                'rows'                 => 5,
                'onkeyup'              => 'Mautic.n8nDispatchOnHsmTextChange(this)',
                'onchange'             => 'Mautic.n8nDispatchOnHsmTextChange(this)',
                'data-onload-callback' => 'n8nDispatchInitHsmVariables',
            ],
        ]);

        $builder->add('variablesJson', HiddenType::class, [
            'required' => false,
            'attr'     => ['class' => 'n8ndispatch-variables-json'],
        ]);

        $builder->add('isPublished', YesNoButtonGroupType::class, [
            'label' => 'mautic.core.form.available',
            'data'  => ($options['data'] ?? null) instanceof HsmTemplate ? $options['data']->isPublished(false) : true,
        ]);

        $builder->add('buttons', FormButtonsType::class);

        if (!empty($options['action'])) {
            $builder->setAction($options['action']);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => HsmTemplate::class]);
    }

    public function getBlockPrefix(): string
    {
        return 'n8ndispatch_hsm_template';
    }
}
