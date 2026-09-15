<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Form\Type;

use Mautic\EmailBundle\Form\Type\EmailListType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Campaign Action form for "Send via n8n (Email)". Two fields:
 *
 * - email: the usual Mautic Email entity picker (same EmailListType core's
 *   own "Send Email" action uses), wired to
 *   Assets/js/campaign-email-dispatch.js (Mautic.n8nDispatchOnEmailChange /
 *   Mautic.n8nDispatchInitVariables) so it can re-fetch that template's
 *   {{variable}} names, live.
 * - variablesJson: a single hidden field holding a JSON object of
 *   {variableName: value}, kept in sync by that same JS as the visible,
 *   dynamically-rendered variable inputs change. A single mapped field
 *   was used instead of one Symfony form field per variable because the
 *   variable count/names differ per Email and change live as the user
 *   picks a different one — Symfony's own dynamic-field-rebuilding
 *   (PRE_SET_DATA/PRE_SUBMIT) only works cleanly when the field being
 *   swapped stays singular (see LeadBundle's CampaignEventLeadFieldValueType
 *   for that pattern); a variable *count* of fields would mean the JS
 *   guessing Symfony's generated field-name prefix to inject new inputs
 *   that still bind back correctly on submit — fragile. A JSON blob in one
 *   field sidesteps that entirely: Symfony only ever sees one field.
 */
class EmailDispatchActionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add(
            'email',
            EmailListType::class,
            [
                'label'      => 'mautic.email.send.selectemails',
                'label_attr' => ['class' => 'control-label'],
                'multiple'   => false,
                'required'   => true,
                'attr'       => [
                    'class'                => 'form-control',
                    'onchange'             => 'Mautic.n8nDispatchOnEmailChange(this)',
                    'data-onload-callback' => 'n8nDispatchInitVariables',
                ],
                'constraints' => [
                    new NotBlank(['message' => 'mautic.email.chooseemail.notblank']),
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
        return 'n8ndispatch_email_send';
    }
}
