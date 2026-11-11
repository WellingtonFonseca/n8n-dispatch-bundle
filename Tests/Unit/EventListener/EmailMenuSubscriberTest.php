<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\EventListener;

use Mautic\CoreBundle\CoreEvents;
use Mautic\CoreBundle\Event\MenuEvent;
use Mautic\CoreBundle\Menu\MenuHelper;
use MauticPlugin\N8nDispatchBundle\EventListener\EmailMenuSubscriber;
use PHPUnit\Framework\TestCase;

class EmailMenuSubscriberTest extends TestCase
{
    private const CHANNELS = 'mautic.core.channels';
    private const ROOT     = 'mautic.n8ndispatch.menu.root';
    private const EMAIL    = 'mautic.email.emails';

    public function testItRunsAfterEveryBundleAddedItsItems(): void
    {
        $events = EmailMenuSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(CoreEvents::BUILD_MENU, $events);
        $this->assertLessThan(0, $events[CoreEvents::BUILD_MENU][1], 'core adds the items of the bundles at 9999');
    }

    public function testTheEmailItemMovesFromChannelsToTheN8nRoot(): void
    {
        $event = $this->eventWithTree($this->tree());

        (new EmailMenuSubscriber())->moveEmailUnderN8nRoot($event);

        $children = $event->getMenuItems()['children'];
        $this->assertArrayNotHasKey(self::EMAIL, $children[self::CHANNELS]['children']);
        $this->assertArrayHasKey('other.channel', $children[self::CHANNELS]['children'], 'the other channels stay');
        $moved = $children[self::ROOT]['children'][self::EMAIL];
        $this->assertSame('mautic_email_index', $moved['route']);
        $this->assertSame(['email:emails:viewown', 'email:emails:viewother'], $moved['access']);
        $this->assertSame(EmailMenuSubscriber::PRIORITY, $moved['priority']);
        $this->assertArrayHasKey('mautic.n8ndispatch.dispatch.menu.index', $children[self::ROOT]['children'], 'the items of the root stay');
    }

    public function testNothingMovesWhenTheRootIsMissing(): void
    {
        $tree = $this->tree();
        unset($tree['children'][self::ROOT]);
        $event = $this->eventWithTree($tree);

        (new EmailMenuSubscriber())->moveEmailUnderN8nRoot($event);

        $this->assertSame($tree, $event->getMenuItems(), 'the email item must not vanish from the menu');
    }

    public function testNothingChangesWhenThereIsNoEmailItem(): void
    {
        $tree = $this->tree();
        unset($tree['children'][self::CHANNELS]['children'][self::EMAIL]);
        $event = $this->eventWithTree($tree);

        (new EmailMenuSubscriber())->moveEmailUnderN8nRoot($event);

        $this->assertSame($tree, $event->getMenuItems());
    }

    public function testTheOtherMenusAreNotTouched(): void
    {
        $tree  = $this->tree();
        $event = $this->eventWithTree($tree, 'admin');

        (new EmailMenuSubscriber())->moveEmailUnderN8nRoot($event);

        $this->assertSame($tree, $event->getMenuItems());
    }

    private function eventWithTree(array $tree, string $type = 'main'): MenuEvent
    {
        $event = new MenuEvent($this->createMock(MenuHelper::class), $type);
        $event->setMenuItems($tree);

        return $event;
    }

    private function tree(): array
    {
        return [
            'children' => [
                self::CHANNELS => [
                    'priority' => 40,
                    'children' => [
                        self::EMAIL => [
                            'route'    => 'mautic_email_index',
                            'access'   => ['email:emails:viewown', 'email:emails:viewother'],
                            'priority' => 100,
                            'extras'   => ['depth' => 1, 'routeName' => 'mautic_email_index'],
                        ],
                        'other.channel' => ['route' => 'mautic_other_index', 'priority' => 50],
                    ],
                ],
                self::ROOT => [
                    'priority' => 35,
                    'children' => [
                        'mautic.n8ndispatch.dispatch.menu.index' => ['route' => 'mautic_n8ndispatch.dispatch_index', 'priority' => 10],
                    ],
                ],
            ],
        ];
    }
}
