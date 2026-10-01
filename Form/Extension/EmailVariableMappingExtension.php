<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Form\Extension;

use Mautic\CoreBundle\Service\FlashBag;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Form\Type\EmailType;
use MauticPlugin\N8nDispatchBundle\Entity\EmailVariablesRepository;
use MauticPlugin\N8nDispatchBundle\Service\TemplateVariableScanner;
use MauticPlugin\N8nDispatchBundle\Service\VariableMappingChecker;
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
 * Blocks saving an Email that already exists while some of its {{variables}} are not mapped on the "Variables"
 * tab. Save and Save & close are the same form submit, so both are covered.
 *
 * The first save of a new Email is never blocked: the tab needs the Email's id, so there is nothing to map yet
 * (EmailUnmappedVariablesSubscriber warns about it instead). From the second save on it applies.
 *
 * The tab's mapping is saved by its own AJAX call right before the form is submitted
 * (Assets/js/email-tab-variables.js), so the saved mapping is what the user just filled in. Through the API there
 * is no tab, so a PATCH to an Email with unmapped variables is refused until they are mapped on screen.
 *
 * Runs after EmailVariablePrefixExtension and steps aside if that already flagged customHtml: names without the
 * n8n_ prefix have to be renamed first, and they would only show up here as unmapped too.
 */
class EmailVariableMappingExtension extends AbstractTypeExtension
{
    public function __construct(
        private TemplateVariableScanner $variableScanner,
        private VariableMappingChecker $mappingChecker,
        private EmailVariablesRepository $emailVariablesRepository,
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

            if (!$email instanceof Email || null === $email->getId()) {
                return;
            }

            $customHtml = $event->getForm()->get('customHtml');

            if (!$customHtml->isValid()) {
                return;
            }

            $names = $this->variableScanner->extract((string) $email->getCustomHtml());

            if ([] === $names) {
                return;
            }

            $mapping = json_decode((string) $this->emailVariablesRepository->getVariablesJsonForEmail((int) $email->getId()), true);

            if ([] === $this->mappingChecker->findUnmapped($names, is_array($mapping) ? $mapping : [])) {
                return;
            }

            $tab     = $this->translator->trans('mautic.n8ndispatch.email.tab.label');
            $message = $this->translator->trans('mautic.n8ndispatch.email.error.unmapped', ['%tab%' => $tab]);

            // Core's API error handling reads $error->getCause()->getCode(), so the cause can't be null.
            $violation = new ConstraintViolation($message, null, [], $email, 'customHtml', $email->getCustomHtml());
            $customHtml->addError(new FormError($message, null, [], null, $violation));

            if (!str_starts_with((string) $this->requestStack->getCurrentRequest()?->getPathInfo(), '/api/')) {
                $this->flashBag->add(
                    'mautic.n8ndispatch.email.error.unmapped',
                    ['%tab%' => '<b>'.htmlspecialchars($tab, ENT_QUOTES).'</b>'],
                    FlashBag::LEVEL_ERROR,
                    'messages'
                );
            }
        }, -10);
    }
}
