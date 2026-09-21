<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Campaign Action form for "Send via n8n (SMS)". Same shape as
 * EmailDispatchActionType (see that class for the full 'status' and
 * 'variablesJson' rationale, reused as-is here), with one difference:
 * there's no Mautic SMS entity to pick from and scan for {{variable}}
 * placeholders the way EmailListType lets the Email one be scanned server
 * side, so 'text' is a plain textarea the user pastes the message into
 * directly, and the {{variable}} names are extracted from that pasted
 * text instead (Controller/AjaxController::getSmsVariablesAction, wired
 * up via Assets/js/campaign-sms-dispatch.js).
 *
 * The DNC/unsubscribe variable EmailMirrorSyncSubscriber auto-injects
 * into an Email template's footer has no SMS equivalent and is never
 * added to this form's variable list — there's no footer here to inject
 * it into.
 */
class SmsDispatchActionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add(
            'status',
            ChoiceType::class,
            [
                'label'      => 'mautic.n8ndispatch.campaign.event.status',
                'label_attr' => ['class' => 'control-label'],
                'choices'    => [
                    'mautic.n8ndispatch.campaign.event.status.test'       => 'test',
                    'mautic.n8ndispatch.campaign.event.status.production' => 'production',
                    'mautic.n8ndispatch.campaign.event.status.paused'     => 'paused',
                ],
                'required' => true,
                'attr'     => [
                    'class' => 'form-control',
                ],
            ]
        );

        $builder->add(
            'text',
            TextareaType::class,
            [
                'label'      => 'mautic.n8ndispatch.campaign.event.sms.text',
                'label_attr' => ['class' => 'control-label'],
                'required'   => true,
                'attr'       => [
                    'class'                => 'form-control',
                    'rows'                 => 4,
                    'onkeyup'              => 'Mautic.n8nDispatchOnSmsTextChange(this)',
                    'onchange'             => 'Mautic.n8nDispatchOnSmsTextChange(this)',
                    'data-onload-callback' => 'n8nDispatchInitSmsVariables',
                ],
                'constraints' => [
                    new NotBlank(['message' => 'mautic.core.value.required']),
                ],
            ]
        );

        $builder->add(
            'variablesJson',
            HiddenType::class,
            [
                'required' => false,
                'attr'     => [
                    'class' => 'n8ndispatch-variables-json',
                ],
            ]
        );
    }

    public function getBlockPrefix(): string
    {
        return 'n8ndispatch_sms_send';
    }
}
