<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Campaign Action form for "Send via n8n (HSM)". Same 'status' and
 * 'variablesJson' shape as EmailDispatchActionType/SmsDispatchActionType
 * (see EmailDispatchActionType's own docblock for the full rationale,
 * reused as-is), but with no Mautic entity or pasted text to derive a
 * template/message from at all: HSM (WhatsApp template messages) has no
 * Mautic-side representation of the template's content, only a reference
 * to one that lives entirely on the WhatsApp/HSM side —
 * Form/Type/EmailDispatchActionType.php's own architecture decision doc
 * called this out from the start ("no native Mautic entity for this
 * channel, so the template reference is a plain field").
 *
 * Two plain fields carry that reference:
 * - 'router': which WhatsApp number/line dispatches the message.
 * - 'hsmId': which HSM template to send.
 *
 * variablesJson's entries are keyed "1", "2", "3", ... in order rather
 * than by name: WhatsApp's own HSM template syntax takes positional
 * variables ($1, $2, ...), not Mautic-style {{name}} tokens, and there's
 * no text here to scan a name out of anyway (unlike Email's picked
 * template or SMS's pasted text). Assets/js/campaign-hsm-dispatch.js's
 * "Add variable" button just appends the next position — reusing the
 * same per-row static/contact-field/Custom-Object source picker UI as
 * Email/SMS (Mautic.n8ndispatchShared, see campaign-email-dispatch.js) —
 * and removing a row renumbers every position after it, so the sequence
 * sent to n8n is always contiguous from 1.
 */
class HsmDispatchActionType extends AbstractType
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
            'router',
            TextType::class,
            [
                'label'      => 'mautic.n8ndispatch.campaign.event.hsm.router',
                'label_attr' => ['class' => 'control-label'],
                'required'   => true,
                'attr'       => [
                    'class' => 'form-control',
                ],
                'constraints' => [
                    new NotBlank(['message' => 'mautic.core.value.required']),
                ],
            ]
        );

        $builder->add(
            'hsmId',
            TextType::class,
            [
                'label'      => 'mautic.n8ndispatch.campaign.event.hsm.hsm_id',
                'label_attr' => ['class' => 'control-label'],
                'required'   => true,
                'attr'       => [
                    'class' => 'form-control',
                    // The variables container/"Add variable" control is
                    // anchored to whichever field carries this attribute
                    // (see campaign-hsm-dispatch.js) — kept on hsmId, the
                    // last visible field, so variables render below both
                    // 'router' and 'hsmId' instead of between them.
                    'data-onload-callback' => 'n8nDispatchInitHsmVariables',
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
        return 'n8ndispatch_hsm_send';
    }
}
