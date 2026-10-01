<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\EventListener;

use Mautic\CoreBundle\CoreEvents;
use Mautic\CoreBundle\Event\CustomContentEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Loads n8ndispatch-shared.js, email-tab-variables.js, email-sender-alert.js,
 * campaign-sms-dispatch.js, campaign-hsm-dispatch.js,
 * campaign-timeline-scheduled.js, campaign-preview-icons.js, and
 * campaign-status-badge.css globally,
 * the same way GrapesJsBuilderBundle injects its own JS/vars via
 * 'page.header.left' (a context rendered on every admin page, not just the
 * campaign builder). Harmless elsewhere — the JS only defines
 * Mautic.n8nDispatch... / n8ndispatch... functions, which nothing calls
 * unless one of the SMS/HSM Template forms, the Email edit page's
 * "Variables" tab, or a contact's Timeline (for
 * campaign-timeline-scheduled.js) is actually on the page, and the CSS
 * only styles .n8ndispatch-* classes, rendered solely by our own
 * Resources/views templates.
 *
 * email-tab-variables.js, campaign-sms-dispatch.js and
 * campaign-hsm-dispatch.js are injected after n8ndispatch-shared.js, and
 * depend on running after it — all three read Mautic.n8ndispatchShared,
 * which that script sets up (n8ndispatch-shared.js used to be named
 * campaign-email-dispatch.js, back when it also backed the "Send via n8n
 * (Email)" campaign action's own Email-picker variable rows — that picker
 * no longer edits variables, see Form/Type/EmailDispatchActionType.php).
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

        $sharedJsRelativePath      = 'Assets/js/n8ndispatch-shared.js';
        $emailTabJsRelativePath    = 'Assets/js/email-tab-variables.js';
        $senderAlertJsRelativePath = 'Assets/js/email-sender-alert.js';
        $smsJsRelativePath         = 'Assets/js/campaign-sms-dispatch.js';
        $hsmJsRelativePath         = 'Assets/js/campaign-hsm-dispatch.js';
        $timelineJsRelativePath    = 'Assets/js/campaign-timeline-scheduled.js';
        $previewIconsJsRelativePath = 'Assets/js/campaign-preview-icons.js';
        $cssRelativePath           = 'Assets/css/campaign-status-badge.css';

        $customContentEvent->addContent(
            '<script src="/plugins/N8nDispatchBundle/'.$sharedJsRelativePath.'?v='.$this->assetVersion($sharedJsRelativePath).'"></script>'
            .'<script src="/plugins/N8nDispatchBundle/'.$emailTabJsRelativePath.'?v='.$this->assetVersion($emailTabJsRelativePath).'"></script>'
            .'<script src="/plugins/N8nDispatchBundle/'.$senderAlertJsRelativePath.'?v='.$this->assetVersion($senderAlertJsRelativePath).'"></script>'
            .'<script src="/plugins/N8nDispatchBundle/'.$smsJsRelativePath.'?v='.$this->assetVersion($smsJsRelativePath).'"></script>'
            .'<script src="/plugins/N8nDispatchBundle/'.$hsmJsRelativePath.'?v='.$this->assetVersion($hsmJsRelativePath).'"></script>'
            .'<script src="/plugins/N8nDispatchBundle/'.$timelineJsRelativePath.'?v='.$this->assetVersion($timelineJsRelativePath).'"></script>'
            .'<script src="/plugins/N8nDispatchBundle/'.$previewIconsJsRelativePath.'?v='.$this->assetVersion($previewIconsJsRelativePath).'"></script>'
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
