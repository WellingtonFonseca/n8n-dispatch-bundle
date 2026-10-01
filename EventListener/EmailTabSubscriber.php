<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\EventListener;

use Mautic\CoreBundle\CoreEvents;
use Mautic\CoreBundle\Event\CustomContentEvent;
use Mautic\EmailBundle\Entity\Email;
use MauticPlugin\N8nDispatchBundle\Entity\EmailVariablesRepository;
use MauticPlugin\N8nDispatchBundle\Service\TemplateVariableScanner;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Adds a "Variables" tab to Mautic's native Email edit page, next to the
 * core Theme/Advanced/Dynamic Content tabs — @MauticEmail/Email/
 * form.html.twig already exposes 'email.tabs' (tab header) and
 * 'email.tabs.content' (tab body) as extension points built for exactly
 * this (see Mautic\CoreBundle\Twig\Helper\ContentHelper), so nothing here
 * overrides a core template. Same mechanism CustomObjectsBundle's
 * ContactTabSubscriber.php already uses to add a tab to the Contact page.
 *
 * The tab itself is a thin shell — a container div carrying the Email's
 * id and its already-saved variablesJson (Entity/EmailVariables.php), plus
 * a hidden field pre-filled with that same JSON. Assets/js/
 * email-tab-variables.js does the actual work on page load: fetches this
 * Email's {{variable}} names (the same plugin:N8nDispatch:getEmailVariables
 * AJAX action the old campaign-action picker used to call), renders the
 * source-picker rows (shared with SMS's own template form via
 * Mautic.n8ndispatchShared), and only persists them to Entity/
 * EmailVariables.php when the page's own native Save/Apply button is
 * actually clicked — never on a bare row edit, since this tab isn't part
 * of Mautic's Email Symfony Form at all (just extra markup injected
 * alongside it), so there's no natural "only on real Save" behavior to
 * inherit for free the way a real form field would get it.
 */
class EmailTabSubscriber implements EventSubscriberInterface
{
    private const VIEW_NAME = '@MauticEmail/Email/form.html.twig';

    public function __construct(
        private EmailVariablesRepository $emailVariablesRepository,
    ) {
    }

    /**
     * @return mixed[]
     */
    public static function getSubscribedEvents(): array
    {
        return [
            CoreEvents::VIEW_INJECT_CUSTOM_CONTENT => 'injectTab',
        ];
    }

    public function injectTab(CustomContentEvent $event): void
    {
        $isTabHeader  = $event->checkContext(self::VIEW_NAME, 'email.tabs');
        $isTabContent = $event->checkContext(self::VIEW_NAME, 'email.tabs.content');

        if (!$isTabHeader && !$isTabContent) {
            return;
        }

        $vars  = $event->getVars();
        $email = $vars['email'] ?? null;

        if (!$email instanceof Email || null === $email->getId()) {
            // Brand-new, unsaved email: no id to key Entity/
            // EmailVariables.php on yet, and nothing worth showing until
            // the email actually exists.
            return;
        }

        if ($isTabHeader) {
            $event->addTemplate('@N8nDispatch/SubscribedEvents/EmailTab/link.html.twig');

            return;
        }

        $savedVariablesJson = $this->emailVariablesRepository->getVariablesJsonForEmail((int) $email->getId()) ?? '{}';

        $event->addTemplate('@N8nDispatch/SubscribedEvents/EmailTab/content.html.twig', [
            'emailId'            => $email->getId(),
            'savedVariablesJson' => $savedVariablesJson,
            'requiredPrefix'     => TemplateVariableScanner::REQUIRED_PREFIX,
        ]);
    }
}
