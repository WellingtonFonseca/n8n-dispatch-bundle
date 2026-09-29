<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\EventListener;

use Mautic\CoreBundle\CoreEvents;
use Mautic\CoreBundle\Event\CustomContentEvent;
use Mautic\EmailBundle\Entity\Email;
use MauticPlugin\N8nDispatchBundle\Service\EmailTemplateUsageFinder;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Adds a "Campaigns" tab to Mautic's native Email edit page, listing
 * every campaign whose "Send via n8n (Email)" step sends this Email —
 * same idea as the "Campaigns using this template" table on the SMS/HSM
 * Template edit pages (Resources/views/SmsTemplate/form.html.twig), just
 * surfaced as its own tab instead of a section on the page, since this
 * page belongs to core (@MauticEmail/Email/form.html.twig), not this
 * plugin.
 *
 * Kept as its own subscriber, separate from EmailTabSubscriber.php (the
 * "Variables N8N" tab): each is a self-contained concern, and
 * CustomContentEvent::addTemplate() already supports several listeners
 * each contributing their own tab to the same 'email.tabs'/
 * 'email.tabs.content' extension points — confirmed by reading
 * Mautic\CoreBundle\Event\CustomContentEvent, templates accumulate in a
 * list rather than being replaced.
 */
class EmailCampaignsTabSubscriber implements EventSubscriberInterface
{
    private const VIEW_NAME = '@MauticEmail/Email/form.html.twig';

    public function __construct(
        private EmailTemplateUsageFinder $usageFinder,
    ) {
    }

    /**
     * A negative priority (default is 0) makes this listener run after
     * EmailTabSubscriber.php's own "Variables N8N" tab — customContent()
     * renders listeners in call order, and higher-priority Symfony
     * listeners run first, so this needs to run later to land last among
     * this plugin's own tabs, right before/after core's hardcoded ones
     * (which are written directly into the Twig template, not through
     * this same extension point at all — see @MauticEmail/Email/
     * form.html.twig, unaffected by this priority either way).
     *
     * @return mixed[]
     */
    public static function getSubscribedEvents(): array
    {
        return [
            CoreEvents::VIEW_INJECT_CUSTOM_CONTENT => ['injectTab', -10],
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
            // Brand-new, unsaved email: no id to look up usages by yet.
            return;
        }

        if ($isTabHeader) {
            $event->addTemplate('@N8nDispatch/SubscribedEvents/EmailTab/campaigns_link.html.twig');

            return;
        }

        $event->addTemplate('@N8nDispatch/SubscribedEvents/EmailTab/campaigns_content.html.twig', [
            'campaignUsages' => $this->usageFinder->findUsages((int) $email->getId()),
        ]);
    }
}
