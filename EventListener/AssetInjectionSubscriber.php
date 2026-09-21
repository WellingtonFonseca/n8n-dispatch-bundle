<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\EventListener;

use Mautic\CoreBundle\CoreEvents;
use Mautic\CoreBundle\Event\CustomContentEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Loads campaign-email-dispatch.js, campaign-sms-dispatch.js,
 * campaign-hsm-dispatch.js, and campaign-status-badge.css globally, the
 * same way GrapesJsBuilderBundle injects its own JS/vars via
 * 'page.header.left' (a context rendered on every admin page, not just
 * the campaign builder). Harmless elsewhere — the JS only defines
 * Mautic.n8nDispatch* functions, which nothing calls unless one of the
 * "Send via n8n (...)" campaign action forms is actually on the page,
 * and the CSS only styles .n8ndispatch-status-badge, rendered solely by
 * Resources/views/Event/_email_send.html.twig.
 *
 * campaign-sms-dispatch.js and campaign-hsm-dispatch.js are injected
 * after campaign-email-dispatch.js, and depend on running after it —
 * both read Mautic.n8ndispatchShared, which the Email script sets up.
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

        $emailJsRelativePath = 'Assets/js/campaign-email-dispatch.js';
        $smsJsRelativePath   = 'Assets/js/campaign-sms-dispatch.js';
        $hsmJsRelativePath   = 'Assets/js/campaign-hsm-dispatch.js';
        $cssRelativePath     = 'Assets/css/campaign-status-badge.css';

        $customContentEvent->addContent(
            '<script src="/plugins/N8nDispatchBundle/'.$emailJsRelativePath.'?v='.$this->assetVersion($emailJsRelativePath).'"></script>'
            .'<script src="/plugins/N8nDispatchBundle/'.$smsJsRelativePath.'?v='.$this->assetVersion($smsJsRelativePath).'"></script>'
            .'<script src="/plugins/N8nDispatchBundle/'.$hsmJsRelativePath.'?v='.$this->assetVersion($hsmJsRelativePath).'"></script>'
            .'<link rel="stylesheet" href="/plugins/N8nDispatchBundle/'.$cssRelativePath.'?v='.$this->assetVersion($cssRelativePath).'">'
        );
    }

    /**
     * Both assets are served with no Cache-Control/Expires header of their
     * own (plain static files under docroot), which leaves a browser free
     * to keep a heuristically-cached copy indefinitely once it's loaded one
     * — confirmed live: an edit to campaign-status-badge.css (the Timeline
     * outcome badge colors) didn't show up in an already-open browser
     * session even after the server-side file, and a full page reload,
     * both had the new content; only a hard refresh picked it up. A
     * filemtime()-based ?v= query string changes the URL itself whenever
     * either file changes, which busts that cache automatically — same
     * effect as Mautic core's own asset versioning, without needing to
     * hook into it for a two-file plugin asset list.
     */
    private function assetVersion(string $relativePath): int
    {
        $absolutePath = dirname(__DIR__).'/'.$relativePath;

        return (false !== ($mtime = @filemtime($absolutePath))) ? $mtime : time();
    }
}
