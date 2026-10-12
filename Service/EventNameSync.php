<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

use Mautic\CampaignBundle\Entity\Event;
use MauticPlugin\N8nDispatchBundle\Twig\EventTemplateExtension;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Gives a campaign action of this plugin the name of the communication it dispatches ("Entrega de Trabalho, E 01"),
 * so the contact's History ("<event name> / <campaign>") says what was sent.
 *
 * When the node's name is left blank, Mautic saves the name of the action's type ("Enviar pelo n8n (Email)"), which
 * says nothing. The name is replaced only when it is blank, is one of those type names (in any language the plugin
 * has) or is the name of the template the event had before (set by this class on an earlier save, so changing the
 * template changes the name too). A name typed by the user is never touched.
 */
class EventNameSync
{
    private const TYPE_LABELS = [
        'n8ndispatch.email.send' => 'mautic.n8ndispatch.campaign.event.email.send',
        'n8ndispatch.sms.send'   => 'mautic.n8ndispatch.campaign.event.sms.send',
        'n8ndispatch.hsm.send'   => 'mautic.n8ndispatch.campaign.event.hsm.send',
    ];

    private const LOCALES = ['en_US', 'pt_BR'];

    /** The longest name the campaign event column holds. */
    private const MAX_LENGTH = 255;

    public function __construct(private EventTemplateExtension $templates, private TranslatorInterface $translator)
    {
    }

    public static function handles(string $type): bool
    {
        return isset(self::TYPE_LABELS[$type]);
    }

    /**
     * @param array<string, mixed>|null $previousProperties the event's properties as they were saved before (null for a new event)
     *
     * @return bool whether the name was changed
     */
    public function sync(Event $event, ?array $previousProperties = null): bool
    {
        $type = (string) $event->getType();
        if (!self::handles($type)) {
            return false;
        }

        $templateName = $this->templates->templateName($type, $event->getProperties());
        if (null === $templateName) {
            return false;
        }

        $templateName = mb_substr($templateName, 0, self::MAX_LENGTH);
        $current      = trim((string) $event->getName());

        if ($current === $templateName || !$this->isReplaceable($current, $type, $previousProperties)) {
            return false;
        }

        $event->setName($templateName);

        return true;
    }

    /**
     * @param array<string, mixed>|null $previousProperties
     */
    private function isReplaceable(string $name, string $type, ?array $previousProperties): bool
    {
        if ('' === $name) {
            return true;
        }

        foreach (self::LOCALES as $locale) {
            if ($name === $this->translator->trans(self::TYPE_LABELS[$type], [], 'messages', $locale)) {
                return true;
            }
        }

        $previous = null === $previousProperties ? null : $this->templates->templateName($type, $previousProperties);

        return null !== $previous && $name === mb_substr($previous, 0, self::MAX_LENGTH);
    }
}
