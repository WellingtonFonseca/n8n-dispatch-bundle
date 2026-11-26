<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\EventListener;

use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Event\EmailEvent;
use MauticPlugin\N8nDispatchBundle\Entity\EmailVariablesRepository;
use MauticPlugin\N8nDispatchBundle\Form\Extension\EmailProviderExtension;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Writes the "Enviar por" the Email form sent (Form/Extension/EmailProviderExtension.php) once the Email is saved
 * and has its id.
 */
class EmailProviderSaveSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private EmailVariablesRepository $emailVariablesRepository,
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
        $attributes = $this->requestStack->getCurrentRequest()?->attributes;
        $provider   = $attributes?->get(EmailProviderExtension::REQUEST_ATTRIBUTE);
        $emailId    = $event->getEmail()->getId();

        if (!is_string($provider) || null === $emailId) {
            return;
        }

        // Used once: another Email saved in the same request (batch API, variant, translation) must not inherit it.
        $attributes->remove(EmailProviderExtension::REQUEST_ATTRIBUTE);

        $this->emailVariablesRepository->saveProviderForEmail((int) $emailId, $provider);
    }
}
