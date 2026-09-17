<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Form\Type;

use Mautic\EmailBundle\Form\Type\EmailListType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Campaign Action form for "Send via n8n (Email)". Three fields, in this
 * display order:
 *
 * - status: a 'test'/'production'/'paused' dropdown, shown first. Stored on
 *   the event's properties and read by CampaignTriggerSubscriber: 'paused'
 *   skips dispatch (contact rescheduled, not failed), 'test'/'production'
 *   both dispatch and are passed through as the payload's own 'status'
 *   field. Values are plain English on purpose — same strings shown in the
 *   dropdown label, not a separate internal vocabulary, since this value
 *   is also sent straight through to the n8n endpoint.
 *   Deliberately does NOT set a 'data' option here: Symfony's 'data' pins
 *   a field to that value permanently, ignoring whatever is actually bound
 *   from the event's own properties — which is exactly the bug this
 *   comment used to describe as a "default" (the field looked stuck on
 *   'test' no matter what was saved, because it was). 'test' being listed
 *   first in 'choices' is what makes a genuinely new, never-saved event
 *   default to it — a required ChoiceType with no bound value and no
 *   placeholder selects its first choice.
 * - email: the usual Mautic Email entity picker (same EmailListType core's
 *   own "Send Email" action uses), wired to
 *   Assets/js/campaign-email-dispatch.js (Mautic.n8nDispatchOnEmailChange /
 *   Mautic.n8nDispatchInitVariables) so it can re-fetch that template's
 *   {{variable}} names, live.
 * - variablesJson: a single hidden field holding a JSON object of
 *   {variableName: {source, value, field, customObject, customObjectField}}
 *   — every key always present, only the ones matching `source` are
 *   meaningful:
 *     - {source: 'static', value: '...'} — a fixed value typed in.
 *     - {source: 'field', field: 'firstname'} — a Mautic contact field,
 *       resolved per-contact at dispatch time.
 *     - {source: 'custom_object', customObject: 'disciplines',
 *       customObjectField: 'discname'} — resolved at dispatch time by
 *       finding this Custom Object's condition in the campaign's source
 *       Segment filter, reapplying it against just this contact's linked
 *       Custom Items, and joining every matching item's field value with
 *       "<br>" (see EventListener/CampaignTriggerSubscriber.php for the
 *       resolution logic — Mirror inserts this value raw into the HTML,
 *       not escaped, so "<br>" renders as real line breaks).
 *   Kept in sync by that same JS as the visible, dynamically-rendered
 *   variable rows change. A single mapped field was used instead of one
 *   Symfony form field per variable because the
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
