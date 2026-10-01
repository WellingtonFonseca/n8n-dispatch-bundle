<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\EventListener;

use Mautic\CoreBundle\Service\FlashBag;
use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Event\EmailEvent;
use MauticPlugin\N8nDispatchBundle\Entity\EmailVariablesRepository;
use MauticPlugin\N8nDispatchBundle\Service\TemplateVariableScanner;
use MauticPlugin\N8nDispatchBundle\Service\VariableMappingChecker;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * After an Email is saved from its edit screen, warns when some of its {{variables}} are still not mapped on the
 * "Variables" tab. The yellow alert above the tabs (Assets/js/email-tab-variables.js) only exists on the edit
 * page, so it never shows to someone who used Save & close; this warning is shown on whichever screen comes next.
 *
 * The tab's mapping is saved by its own AJAX call right before the form is submitted, so by the time this runs
 * it is already in the database. For a brand-new Email there is no tab yet (it needs the Email's id), so every
 * variable counts as unmapped, which is exactly the case this exists for.
 *
 * Only for the edit screen (its fields are posted as emailform[...]); the API has no screen to show it on.
 */
class EmailUnmappedVariablesSubscriber implements EventSubscriberInterface
{
    private bool $warned = false;

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
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            EmailEvents::EMAIL_POST_SAVE => 'onEmailPostSave',
        ];
    }

    public function onEmailPostSave(EmailEvent $event): void
    {
        if ($this->warned || !$this->requestStack->getCurrentRequest()?->request->has('emailform')) {
            return;
        }

        $email = $event->getEmail();
        $names = $this->variableScanner->extract((string) $email->getCustomHtml());

        if ([] === $names) {
            return;
        }

        $mapping = json_decode((string) $this->emailVariablesRepository->getVariablesJsonForEmail((int) $email->getId()), true);

        if ([] === $this->mappingChecker->findUnmapped($names, is_array($mapping) ? $mapping : [])) {
            return;
        }

        $this->warned = true;

        $tab = $this->translator->trans('mautic.n8ndispatch.email.tab.label');

        $this->flashBag->add(
            'mautic.n8ndispatch.email.warning.unmapped_after_save',
            ['%tab%' => '<b>'.htmlspecialchars($tab, ENT_QUOTES).'</b>'],
            FlashBag::LEVEL_WARNING,
            'messages'
        );
    }
}
