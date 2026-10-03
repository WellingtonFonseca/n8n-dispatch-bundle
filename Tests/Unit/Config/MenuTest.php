<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Config;

use MauticPlugin\N8nDispatchBundle\Controller\DispatchController;
use PHPUnit\Framework\TestCase;

/**
 * The plugin's own entry in Mautic's main menu (not under Channels), with the
 * Dispatches page as its submenu.
 */
class MenuTest extends TestCase
{
    private array $config;

    protected function setUp(): void
    {
        $this->config = require __DIR__.'/../../../Config/config.php';
    }

    public function testTheMainMenuHasAnN8nDispatchRootWithNoParent(): void
    {
        $root = $this->config['menu']['main']['mautic.n8ndispatch.menu.root'] ?? null;

        $this->assertNotNull($root);
        $this->assertArrayNotHasKey('parent', $root, 'a root item must not sit inside another menu');
        $this->assertArrayHasKey('iconClass', $root);
    }

    public function testDispatchesIsASubmenuOfTheRootAndPointsToARealRoute(): void
    {
        $item = $this->config['menu']['main']['mautic.n8ndispatch.dispatch.menu.index'] ?? null;

        $this->assertNotNull($item);
        $this->assertSame('mautic.n8ndispatch.menu.root', $item['parent']);
        $this->assertArrayHasKey($item['route'], $this->config['routes']['main']);
    }

    public function testTheTemplateScreensStayUnderChannels(): void
    {
        $main = $this->config['menu']['main'];

        $this->assertSame('mautic.core.channels', $main['mautic.n8ndispatch.smstemplate.menu.index']['parent']);
        $this->assertSame('mautic.core.channels', $main['mautic.n8ndispatch.hsmtemplate.menu.index']['parent']);
    }

    public function testTheMenuAsksForTheSamePermissionAsTheScreen(): void
    {
        $main = $this->config['menu']['main'];

        $this->assertSame('campaign:campaigns:full', DispatchController::PERMISSION);
        $this->assertSame(DispatchController::PERMISSION, $main['mautic.n8ndispatch.menu.root']['access']);
        $this->assertSame(DispatchController::PERMISSION, $main['mautic.n8ndispatch.dispatch.menu.index']['access']);
    }
}
