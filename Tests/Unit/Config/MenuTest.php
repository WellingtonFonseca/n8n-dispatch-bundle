<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Config;

use MauticPlugin\N8nDispatchBundle\Controller\DispatchController;
use MauticPlugin\N8nDispatchBundle\EventListener\EmailMenuSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The plugin's own entry in Mautic's main menu (not under Channels), with the
 * Dispatches, SMS template and HSM template pages as its submenu. The Email page
 * of core joins it through EmailMenuSubscriber.
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

    public function testTheTemplateScreensAreSubmenusOfTheRoot(): void
    {
        $main = $this->config['menu']['main'];

        $this->assertSame('mautic.n8ndispatch.menu.root', $main['mautic.n8ndispatch.smstemplate.menu.index']['parent']);
        $this->assertSame('mautic.n8ndispatch.menu.root', $main['mautic.n8ndispatch.hsmtemplate.menu.index']['parent']);
        $this->assertArrayHasKey($main['mautic.n8ndispatch.smstemplate.menu.index']['route'], $this->config['routes']['main']);
        $this->assertArrayHasKey($main['mautic.n8ndispatch.hsmtemplate.menu.index']['route'], $this->config['routes']['main']);
    }

    public function testTheSubmenuOrderIsDispatchesEmailSmsHsm(): void
    {
        $main = $this->config['menu']['main'];
        $dispatch = $main['mautic.n8ndispatch.dispatch.menu.index']['priority'];
        $sms      = $main['mautic.n8ndispatch.smstemplate.menu.index']['priority'];
        $hsm      = $main['mautic.n8ndispatch.hsmtemplate.menu.index']['priority'];

        $this->assertGreaterThan(EmailMenuSubscriber::PRIORITY, $dispatch);
        $this->assertGreaterThan($sms, EmailMenuSubscriber::PRIORITY);
        $this->assertGreaterThan($hsm, $sms);
    }

    public function testTheMenuAsksForTheSamePermissionAsTheScreen(): void
    {
        $main = $this->config['menu']['main'];

        $this->assertSame('campaign:campaigns:full', DispatchController::PERMISSION);
        $this->assertSame(DispatchController::PERMISSION, $main['mautic.n8ndispatch.dispatch.menu.index']['access']);
        $this->assertSame('sms:smses:viewown', $main['mautic.n8ndispatch.smstemplate.menu.index']['access']);
        $this->assertSame('sms:smses:viewown', $main['mautic.n8ndispatch.hsmtemplate.menu.index']['access']);
    }

    public function testTheRootIsShownToAnyUserWhoCanOpenOneOfItsSubmenus(): void
    {
        $main   = $this->config['menu']['main'];
        $access = $main['mautic.n8ndispatch.menu.root']['access'];

        // core drops a submenu whose parent is hidden and shows it at the top of the menu instead
        $this->assertIsArray($access);
        foreach ([DispatchController::PERMISSION, 'sms:smses:viewown', 'email:emails:viewown', 'email:emails:viewother'] as $permission) {
            $this->assertContains($permission, $access);
        }
    }

    #[DataProvider('languages')]
    public function testTheSmsAndHsmItemsAreNamedOnlyByTheChannel(string $language): void
    {
        $messages = parse_ini_file(__DIR__.'/../../../Translations/'.$language.'/messages.ini');

        // inside the N8N Dispatch menu the "(n8n)" of the old Channels label says nothing
        $this->assertSame('SMS', $messages['mautic.n8ndispatch.smstemplate.menu.index']);
        $this->assertSame('HSM', $messages['mautic.n8ndispatch.hsmtemplate.menu.index']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function languages(): array
    {
        return ['pt_BR' => ['pt_BR'], 'en_US' => ['en_US']];
    }
}
