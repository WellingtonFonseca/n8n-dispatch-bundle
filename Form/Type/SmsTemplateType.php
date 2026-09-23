<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Form\Type;

use Mautic\CoreBundle\Form\EventListener\CleanFormSubscriber;
use Mautic\CoreBundle\Form\EventListener\FormExitSubscriber;
use Mautic\CoreBundle\Form\Type\FormButtonsType;
use Mautic\CoreBundle\Form\Type\YesNoButtonGroupType;
use MauticPlugin\N8nDispatchBundle\Entity\SmsTemplate;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Create/edit form for an SmsTemplate. 'text' and 'variablesJson' are
 * wired exactly like they used to be on the SMS Campaign Action's own
 * form (same JS callbacks, same hidden-field class), so
 * Assets/js/campaign-sms-dispatch.js renders the variable-source rows
 * here without any changes of its own.
 *
 * @extends AbstractType<SmsTemplate>
 */
class SmsTemplateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // 'text' and 'variablesJson' are kept 'raw': any field not listed
        // here gets InputHelper's default tag-stripping filter, which could
        // alter a message that legitimately contains '<' or '&' (it's
        // only ever sent as an SMS body, never rendered as HTML) or the
        // JSON blob the variable rows write into the hidden field.
        $builder->addEventSubscriber(new CleanFormSubscriber([
            'description'   => 'html',
            'text'          => 'raw',
            'variablesJson' => 'raw',
        ]));
        $builder->addEventSubscriber(new FormExitSubscriber('n8ndispatch.smstemplate', $options));

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

        $builder->add('text', TextareaType::class, [
            'label'      => 'mautic.n8ndispatch.campaign.event.sms.text',
            'label_attr' => ['class' => 'control-label'],
            'attr'       => [
                'class'                => 'form-control',
                'rows'                 => 5,
                'onkeyup'              => 'Mautic.n8nDispatchOnSmsTextChange(this)',
                'onchange'             => 'Mautic.n8nDispatchOnSmsTextChange(this)',
                'data-onload-callback' => 'n8nDispatchInitSmsVariables',
            ],
        ]);

        $builder->add('variablesJson', HiddenType::class, [
            'required' => false,
            'attr'     => ['class' => 'n8ndispatch-variables-json'],
        ]);

        $builder->add('isPublished', YesNoButtonGroupType::class, [
            'label' => 'mautic.core.form.available',
            'data'  => ($options['data'] ?? null) instanceof SmsTemplate ? $options['data']->isPublished(false) : true,
        ]);

        $builder->add('buttons', FormButtonsType::class);

        if (!empty($options['action'])) {
            $builder->setAction($options['action']);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SmsTemplate::class]);
    }

    public function getBlockPrefix(): string
    {
        return 'n8ndispatch_sms_template';
    }
}
