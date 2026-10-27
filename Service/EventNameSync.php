<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Service;

use Mautic\CampaignBundle\Entity\Event;
use MauticPlugin\N8nDispatchBundle\Twig\EventTemplateExtension;

/**
 * Gives a campaign action of this plugin the name of the communication it dispatches ("Entrega de Trabalho, E 01"),
 * so the contact's History ("<event name> / <campaign>") says what was sent.
 *
 * The name is ALWAYS the template's, whatever was typed: a name left as it was (a copied node, a renamed template,
 * a template swapped) used to point at a communication the node no longer sends. A node with no template picked, or
 * whose template is gone, keeps the name it has.
 */
class EventNameSync
{
    private const TYPES = ['n8ndispatch.email.send', 'n8ndispatch.sms.send', 'n8ndispatch.hsm.send'];

    /** The longest name the campaign event column holds. */
    private const MAX_LENGTH = 255;

    public function __construct(private EventTemplateExtension $templates)
    {
    }

    public static function handles(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    /**
     * @return bool whether the name was changed
     */
    public function sync(Event $event): bool
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
        if ((string) $event->getName() === $templateName) {
            return false;
        }

        $event->setName($templateName);

        return true;
    }
}
