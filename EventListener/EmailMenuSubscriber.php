<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\EventListener;

use Mautic\CoreBundle\CoreEvents;
use Mautic\CoreBundle\Event\MenuEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Moves the Email item of core (Channels > Emails) into the N8N Dispatch menu.
 *
 * The item belongs to the config of EmailBundle, and a plugin config cannot take it away: the same key under
 * another parent would show the item twice. So this runs after core has added every item (it does that at
 * priority 9999) and edits the finished tree.
 */
final class EmailMenuSubscriber implements EventSubscriberInterface
{
    public const PRIORITY = 6;

    private const CHANNELS = 'mautic.core.channels';
    private const ROOT     = 'mautic.n8ndispatch.menu.root';
    private const EMAIL    = 'mautic.email.emails';

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [CoreEvents::BUILD_MENU => ['moveEmailUnderN8nRoot', -9999]];
    }

    public function moveEmailUnderN8nRoot(MenuEvent $event): void
    {
        if ('main' !== $event->getType()) {
            return;
        }

        $tree  = $event->getMenuItems();
        $email = $tree['children'][self::CHANNELS]['children'][self::EMAIL] ?? null;

        // Defensive: the root asks for the same permissions as the Email item, so it is there whenever the item is. If it is not, the item stays in Channels
        if (null === $email || !isset($tree['children'][self::ROOT])) {
            return;
        }

        unset($tree['children'][self::CHANNELS]['children'][self::EMAIL]);
        $email['priority']                                       = self::PRIORITY;
        $tree['children'][self::ROOT]['children'][self::EMAIL] = $email;

        $event->setMenuItems($tree);
    }
}
