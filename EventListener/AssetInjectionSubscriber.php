<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\EventListener;

use Mautic\CoreBundle\CoreEvents;
use Mautic\CoreBundle\Event\CustomContentEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Loads campaign-email-dispatch.js and campaign-status-badge.css globally,
 * the same way GrapesJsBuilderBundle injects its own JS/vars via
 * 'page.header.left' (a context rendered on every admin page, not just the
 * campaign builder). Harmless elsewhere — the JS only defines
 * Mautic.n8nDispatch* functions, which nothing calls unless the "Send via
 * n8n (Email)" campaign action form is actually on the page, and the CSS
 * only styles .n8ndispatch-status-badge, rendered solely by
 * Resources/views/Event/_email_send.html.twig.
 */
class AssetInjectionSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            CoreEvents::VIEW_INJECT_CUSTOM_CONTENT => 'injectViewCustomContent',
        ];
    }

    public function injectViewCustomContent(CustomContentEvent $customContentEvent): void
    {
        if ('page.header.left' !== $customContentEvent->getContext()) {
            return;
        }

        $customContentEvent->addContent(
            '<script src="/plugins/N8nDispatchBundle/Assets/js/campaign-email-dispatch.js"></script>'
            .'<link rel="stylesheet" href="/plugins/N8nDispatchBundle/Assets/css/campaign-status-badge.css">'
        );
    }
}
