<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\EventListener;

use Mautic\CoreBundle\CoreEvents;
use Mautic\CoreBundle\Event\CustomContentEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Adds a hidden element to the Advanced tab of the Email form (core's 'email.settings.advanced' extension point,
 * right after the From name / From address fields) that carries the translated texts for the alert and pulsing dot
 * shown while those required fields are blank (Assets/js/email-sender-alert.js). Unlike the Variables N8N tab it
 * is rendered for a new Email too, which is where the sender has to be filled in first.
 */
class EmailSenderAlertSubscriber implements EventSubscriberInterface
{
    private const VIEW_NAME = '@MauticEmail/Email/form.html.twig';

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            CoreEvents::VIEW_INJECT_CUSTOM_CONTENT => 'injectConfig',
        ];
    }

    public function injectConfig(CustomContentEvent $event): void
    {
        if (!$event->checkContext(self::VIEW_NAME, 'email.settings.advanced')) {
            return;
        }

        $event->addTemplate('@N8nDispatch/SubscribedEvents/EmailAdvanced/sender_alert.html.twig');
    }
}
