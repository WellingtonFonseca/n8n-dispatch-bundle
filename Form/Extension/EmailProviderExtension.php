<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Form\Extension;

use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Form\Type\EmailType;
use MauticPlugin\N8nDispatchBundle\Entity\EmailVariables;
use MauticPlugin\N8nDispatchBundle\Entity\EmailVariablesRepository;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\Constraints\Choice;

/**
 * The "Enviar por" select of the Email edit page: how the Email of a campaign step goes out, through n8n (the
 * webhook) or through Mautic's own mailer. Not mapped to the Email entity: the value lives in
 * n8n_dispatch_email_variables. Assets/js/email-provider-field.js moves the row above the Subject, where core's
 * template has no extension point.
 *
 * The value is kept on the request (per Email, by spl_object_id) here and written by EventListener/EmailProviderSaveSubscriber once the Email
 * is saved, so a brand-new Email (no id yet) gets it too. A request that does not send the field (an API call of
 * other fields) leaves the stored value alone (a new Email then reads as n8n).
 */
class EmailProviderExtension extends AbstractTypeExtension
{
    public const FIELD             = 'n8nProvider';
    public const REQUEST_ATTRIBUTE = '_n8ndispatch_email_provider';

    public function __construct(
        private EmailVariablesRepository $emailVariablesRepository,
        private RequestStack $requestStack,
    ) {
    }

    /**
     * @return iterable<class-string>
     */
    public static function getExtendedTypes(): iterable
    {
        return [EmailType::class];
    }

    /**
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $email   = $options['data'] ?? null;
        $emailId = $email instanceof Email ? $email->getId() : null;
        $choices = [
            'mautic.n8ndispatch.email.provider.n8n'    => EmailVariables::PROVIDER_N8N,
            'mautic.n8ndispatch.email.provider.mautic' => EmailVariables::PROVIDER_MAUTIC,
        ];

        $builder->add(self::FIELD, ChoiceType::class, [
            'label'       => 'mautic.n8ndispatch.email.provider.label',
            'label_attr'  => ['class' => 'control-label'],
            'choices'     => $choices,
            'mapped'      => false,
            'required'    => false,
            'placeholder' => false,
            'data'        => null === $emailId ? EmailVariables::PROVIDER_N8N : $this->emailVariablesRepository->getProviderForEmail((int) $emailId),
            'attr'        => [
                'class'                => 'form-control',
                'data-onload-callback' => 'n8nDispatchMoveEmailProviderField',
            ],
            'constraints' => [new Choice(array_values($choices))],
        ]);

        // Priority below the validator (0): only a fully valid form hands its value over.
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $form  = $event->getForm();
            $email = $form->getData();
            $field = $form->get(self::FIELD);

            if (!$email instanceof Email || !$form->isValid() || !$field->isSubmitted() || !is_string($field->getData()) || '' === $field->getData()) {
                return;
            }

            $attributes = $this->requestStack->getCurrentRequest()?->attributes;

            if (null === $attributes) {
                return;
            }

            // Keyed by Email: a batch request saves several, each must get only its own value.
            $byEmail                        = (array) $attributes->get(self::REQUEST_ATTRIBUTE, []);
            $byEmail[spl_object_id($email)] = $field->getData();
            $attributes->set(self::REQUEST_ATTRIBUTE, $byEmail);
        }, -10);
    }
}
