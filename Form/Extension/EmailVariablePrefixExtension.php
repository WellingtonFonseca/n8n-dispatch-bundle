<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Form\Extension;

use Mautic\CoreBundle\Service\FlashBag;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Form\Type\EmailType;
use MauticPlugin\N8nDispatchBundle\Service\TemplateVariableScanner;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Blocks saving an existing Email whose HTML has {{variables}} without the
 * TemplateVariableScanner::REQUIRED_PREFIX ("n8n_"). The first save of a new
 * Email is never blocked (see the check in buildForm()). Both the Email edit
 * screen and the REST API (/api/emails) build the Email through this same
 * form type, so one check covers UI and API; an invalid form is never
 * saved (the API answers 400 with the message).
 *
 * The error is attached to customHtml, which is what the API reports. That
 * field is hidden when the builder is used, so the UI also gets a flash
 * message (skipped for API requests, where there is no screen to show it).
 *
 * Runs on every submit of the Email form, not only when customHtml changed:
 * an existing template with old variable names has to be fixed the next
 * time it is saved, whatever else was edited.
 */
class EmailVariablePrefixExtension extends AbstractTypeExtension
{
    public function __construct(
        private TemplateVariableScanner $variableScanner,
        private TranslatorInterface $translator,
        #[Autowire(service: 'mautic.core.service.flashbag')]
        private FlashBag $flashBag,
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
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $email = $event->getData();

            // Never on the first save of a new Email: templates often come from other tools that don't use the
            // prefix, and the user has to remap them anyway, so they are adjusted from the second save on.
            if (!$email instanceof Email || null === $email->getId()) {
                return;
            }

            if ([] === $this->variableScanner->extractWithoutPrefix((string) $email->getCustomHtml())) {
                return;
            }

            // Same wording as the alert on the edit page (Assets/js/email-tab-variables.js): the tab's name is plain
            // text in the form error (what the API returns) and bold in the on-screen message.
            $tab    = $this->translator->trans('mautic.n8ndispatch.email.tab.label');
            $prefix = TemplateVariableScanner::REQUIRED_PREFIX;

            $message = $this->translator->trans('mautic.n8ndispatch.email.alert.prefix', ['%tab%' => $tab, '%prefix%' => $prefix]);

            // Core's API error handling reads $error->getCause()->getCode(), so the cause can't be null.
            $violation = new ConstraintViolation($message, null, [], $email, 'customHtml', $email->getCustomHtml());
            $event->getForm()->get('customHtml')->addError(new FormError($message, null, [], null, $violation));

            if (!str_starts_with((string) $this->requestStack->getCurrentRequest()?->getPathInfo(), '/api/')) {
                $this->flashBag->add(
                    'mautic.n8ndispatch.email.alert.prefix',
                    ['%tab%' => '<b>'.htmlspecialchars($tab, ENT_QUOTES).'</b>', '%prefix%' => $prefix],
                    FlashBag::LEVEL_ERROR,
                    'messages'
                );
            }
        });
    }
}
