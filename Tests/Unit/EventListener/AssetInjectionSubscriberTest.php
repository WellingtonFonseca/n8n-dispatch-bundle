<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\EventListener;

use Mautic\CoreBundle\Event\CustomContentEvent;
use MauticPlugin\N8nDispatchBundle\EventListener\AssetInjectionSubscriber;
use PHPUnit\Framework\TestCase;

class AssetInjectionSubscriberTest extends TestCase
{
    public function testIgnoresContextsOtherThanPageHeaderLeft(): void
    {
        $subscriber = new AssetInjectionSubscriber();
        $event      = new CustomContentEvent('some.view', 'some.other.context');

        $subscriber->injectViewCustomContent($event);

        $this->assertSame([], $event->getContent());
    }

    public function testInjectsScriptAndStylesheetWithACacheBustingVersion(): void
    {
        $subscriber = new AssetInjectionSubscriber();
        $event      = new CustomContentEvent('some.view', 'page.header.left');

        $subscriber->injectViewCustomContent($event);

        $content = implode('', $event->getContent());

        $sharedJsMtime   = filemtime(__DIR__.'/../../../Assets/js/n8ndispatch-shared.js');
        $emailTabJsMtime = filemtime(__DIR__.'/../../../Assets/js/email-tab-variables.js');
        $smsJsMtime      = filemtime(__DIR__.'/../../../Assets/js/campaign-sms-dispatch.js');
        $hsmJsMtime      = filemtime(__DIR__.'/../../../Assets/js/campaign-hsm-dispatch.js');
        $cssMtime        = filemtime(__DIR__.'/../../../Assets/css/campaign-status-badge.css');

        $this->assertStringContainsString(
            '<script src="/plugins/N8nDispatchBundle/Assets/js/n8ndispatch-shared.js?v='.$sharedJsMtime.'"></script>',
            $content
        );
        $this->assertStringContainsString(
            '<script src="/plugins/N8nDispatchBundle/Assets/js/email-tab-variables.js?v='.$emailTabJsMtime.'"></script>',
            $content
        );
        $this->assertStringContainsString(
            '<script src="/plugins/N8nDispatchBundle/Assets/js/campaign-sms-dispatch.js?v='.$smsJsMtime.'"></script>',
            $content
        );
        $this->assertStringContainsString(
            '<script src="/plugins/N8nDispatchBundle/Assets/js/campaign-hsm-dispatch.js?v='.$hsmJsMtime.'"></script>',
            $content
        );
        $this->assertStringContainsString(
            '<link rel="stylesheet" href="/plugins/N8nDispatchBundle/Assets/css/campaign-status-badge.css?v='.$cssMtime.'">',
            $content
        );
    }
}
