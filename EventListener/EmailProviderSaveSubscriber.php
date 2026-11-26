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
        $byEmail    = $attributes?->get(EmailProviderExtension::REQUEST_ATTRIBUTE);
        $key        = spl_object_id($event->getEmail());
        $emailId    = $event->getEmail()->getId();

        if (!is_array($byEmail) || !is_string($byEmail[$key] ?? null) || null === $emailId) {
            return;
        }

        $provider = $byEmail[$key];

        // Used once: another Email saved in the same request (batch API, variant, translation) must not inherit it.
        unset($byEmail[$key]);
        $attributes->set(EmailProviderExtension::REQUEST_ATTRIBUTE, $byEmail);

        $this->emailVariablesRepository->saveProviderForEmail((int) $emailId, $provider);
    }
}
