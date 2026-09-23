<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Form\Type;

use MauticPlugin\N8nDispatchBundle\Model\SmsTemplateModel;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Campaign Action form for "Send via n8n (SMS)": the 'status' dropdown
 * (same 'test'/'production'/'paused' rationale as
 * EmailDispatchActionType's docblock) plus a picker for which
 * SmsTemplate to send. The message text and its {{variable}} source
 * mapping used to live right here on the action; they now live on the
 * template (Entity/SmsTemplate.php, edited under Channels > SMS
 * Templates (n8n)) so one template can serve several campaigns and is
 * edited in one place. Only the template's id is stored on the event, as
 * 'smsTemplate' — SmsCampaignTriggerSubscriber loads the template itself
 * at dispatch time.
 *
 * Only published templates are offered.
 */
class SmsDispatchActionType extends AbstractType
{
    public function __construct(
        private SmsTemplateModel $smsTemplateModel,
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
            'smsTemplate',
            ChoiceType::class,
            [
                'label'       => 'mautic.n8ndispatch.campaign.event.sms.template',
                'label_attr'  => ['class' => 'control-label'],
                'choices'     => $this->smsTemplateModel->getRepository()->getPublishedChoices(),
                'placeholder' => 'mautic.n8ndispatch.campaign.event.sms.template.placeholder',
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
        return 'n8ndispatch_sms_send';
    }
}
