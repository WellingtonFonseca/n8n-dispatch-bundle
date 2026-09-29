<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Form\Type;

use Mautic\EmailBundle\Form\Type\EmailListType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Campaign Action form for "Send via n8n (Email)": the 'status' dropdown
 * (same 'test'/'production'/'paused' rationale below) plus the usual
 * Mautic Email entity picker (same EmailListType core's own "Send Email"
 * action uses).
 *
 * The {{variable}} source mapping used to live right here, as a third
 * hidden field kept in sync by JS as the Email picker changed (see git
 * history). It now lives on the Email itself instead — the "Variables" tab
 * EventListener/EmailTabSubscriber.php adds to Mautic's native Email edit
 * page, backed by Entity/EmailVariables.php — so one Email can be sent by
 * several campaigns and its variable mapping is edited in one place, same
 * reasoning already applied to SMS/HSM (see Form/Type/
 * SmsDispatchActionType.php's own docblock, the precedent this mirrors).
 * CampaignTriggerSubscriber.php reads it fresh from there at dispatch
 * time, falling back to a pre-existing event's own inline
 * properties.variablesJson only if the Email has no row there yet — see
 * that class's own docblock.
 *
 * - status: shown first. Stored on the event's properties and read by
 *   CampaignTriggerSubscriber: 'paused' skips dispatch (contact
 *   rescheduled, not failed), 'test'/'production' both dispatch and are
 *   passed through as the payload's own 'status' field. Values are plain
 *   English on purpose — same strings shown in the dropdown label, not a
 *   separate internal vocabulary, since this value is also sent straight
 *   through to the n8n endpoint.
 *   Deliberately does NOT set a 'data' option here: Symfony's 'data' pins
 *   a field to that value permanently, ignoring whatever is actually bound
 *   from the event's own properties — which is exactly the bug this
 *   comment used to describe as a "default" (the field looked stuck on
 *   'test' no matter what was saved, because it was). 'test' being listed
 *   first in 'choices' is what makes a genuinely new, never-saved event
 *   default to it — a required ChoiceType with no bound value and no
 *   placeholder selects its first choice.
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
                    'class' => 'form-control',
                ],
                'constraints' => [
                    new NotBlank(['message' => 'mautic.email.chooseemail.notblank']),
                ],
            ]
        );
    }

    public function getBlockPrefix(): string
    {
        return 'n8ndispatch_email_send';
    }
}
