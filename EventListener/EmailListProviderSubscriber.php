<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\EventListener;

use Mautic\CoreBundle\CoreEvents;
use Mautic\CoreBundle\Event\CustomContentEvent;
use Mautic\EmailBundle\Entity\Email;
use MauticPlugin\N8nDispatchBundle\Entity\EmailVariables;
use MauticPlugin\N8nDispatchBundle\Entity\EmailVariablesRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Feeds the "Enviar por" column of the Email list (Assets/js/email-list-provider.js): the list has no hook for a column,
 * but 'email.name' prints inside the name cell, so each row gets a hidden marker with its provider (n8n or Mautic; an
 * Email with no stored value reads as n8n) and the words, and the script builds the column from the markers.
 */
class EmailListProviderSubscriber implements EventSubscriberInterface
{
    /**
     * Ids of the Emails sent through Mautic, loaded once on the first row (one query per request, not per row).
     *
     * @var array<int, true>|null
     */
    private ?array $mauticEmailIds = null;

    public function __construct(
        private EmailVariablesRepository $emailVariablesRepository,
        private TranslatorInterface $translator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [CoreEvents::VIEW_INJECT_CUSTOM_CONTENT => 'injectViewCustomContent'];
    }

    public function injectViewCustomContent(CustomContentEvent $customContentEvent): void
    {
        $item = $customContentEvent->getVars()['item'] ?? null;
        if ('email.name' !== $customContentEvent->getContext() || !$item instanceof Email || null === $item->getId()) {
            return;
        }

        $this->mauticEmailIds ??= $this->emailVariablesRepository->getMauticEmailIds();
        $provider = isset($this->mauticEmailIds[(int) $item->getId()]) ? EmailVariables::PROVIDER_MAUTIC : EmailVariables::PROVIDER_N8N;
        $customContentEvent->addContent(sprintf(
            '<span class="n8ndispatch-email-provider" hidden data-provider="%s" data-header="%s" data-label="%s"></span>',
            htmlspecialchars($provider, ENT_QUOTES),
            htmlspecialchars($this->translator->trans('mautic.n8ndispatch.email.provider.label'), ENT_QUOTES),
            htmlspecialchars($this->translator->trans('mautic.n8ndispatch.email.provider.'.$provider), ENT_QUOTES)
        ));
    }
}
