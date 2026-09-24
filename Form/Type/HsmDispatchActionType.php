<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Form\Type;

use MauticPlugin\N8nDispatchBundle\Model\HsmTemplateModel;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Campaign Action form for "Send via n8n (HSM)": the 'status' dropdown
 * (same 'test'/'production'/'paused' rationale as
 * EmailDispatchActionType's docblock) plus a picker for which HsmTemplate
 * to send. 'router' and 'hsmId' — and the per-campaign positional
 * ($1, $2, ...) variable picker that used to sit right here — used to
 * live on this form; they now live on the template (Entity/
 * HsmTemplate.php, edited under Channels > HSM Templates (n8n)) so one
 * template can serve several campaigns and is edited in one place. The
 * variable picker was dropped outright, not moved. Only the template's
 * id is stored on the event, as 'hsmTemplate' —
 * HsmCampaignTriggerSubscriber loads the template itself at dispatch
 * time. Same shape as Form/Type/SmsDispatchActionType.php's own
 * post-template-screen form.
 *
 * Only published templates are offered.
 */
class HsmDispatchActionType extends AbstractType
{
    public function __construct(
        private HsmTemplateModel $hsmTemplateModel,
    ) {
    }

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
            'hsmTemplate',
            ChoiceType::class,
            [
                'label'       => 'mautic.n8ndispatch.campaign.event.hsm.template',
                'label_attr'  => ['class' => 'control-label'],
                'choices'     => $this->hsmTemplateModel->getRepository()->getPublishedChoices(),
                'placeholder' => 'mautic.n8ndispatch.campaign.event.hsm.template.placeholder',
                'required'    => true,
                'attr'        => [
                    'class' => 'form-control',
                ],
                'constraints' => [
                    new NotBlank(['message' => 'mautic.core.value.required']),
                ],
            ]
        );
    }

    public function getBlockPrefix(): string
    {
        return 'n8ndispatch_hsm_send';
    }
}
