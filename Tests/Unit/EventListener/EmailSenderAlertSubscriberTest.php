<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\EventListener;

use Mautic\CoreBundle\CoreEvents;
use Mautic\CoreBundle\Event\CustomContentEvent;
use MauticPlugin\N8nDispatchBundle\EventListener\EmailSenderAlertSubscriber;
use PHPUnit\Framework\TestCase;

class EmailSenderAlertSubscriberTest extends TestCase
{
    private const VIEW = '@MauticEmail/Email/form.html.twig';

    public function testListensToTheCustomContentEvent(): void
    {
        $this->assertArrayHasKey(CoreEvents::VIEW_INJECT_CUSTOM_CONTENT, EmailSenderAlertSubscriber::getSubscribedEvents());
    }

    public function testAddsTheConfigElementInsideTheAdvancedTabOfTheEmailForm(): void
    {
        $event = new CustomContentEvent(self::VIEW, 'email.settings.advanced');

        (new EmailSenderAlertSubscriber())->injectConfig($event);

        $templates = $event->getTemplates();
        $this->assertCount(1, $templates);
        $this->assertSame('@N8nDispatch/SubscribedEvents/EmailAdvanced/sender_alert.html.twig', $templates[0]['template']);
    }

    public function testIgnoresOtherContextsOfTheEmailForm(): void
    {
        $event = new CustomContentEvent(self::VIEW, 'email.tabs');

        (new EmailSenderAlertSubscriber())->injectConfig($event);

        $this->assertSame([], $event->getTemplates());
    }

    public function testIgnoresOtherViews(): void
    {
        $event = new CustomContentEvent('@MauticLead/Lead/form.html.twig', 'email.settings.advanced');

        (new EmailSenderAlertSubscriber())->injectConfig($event);

        $this->assertSame([], $event->getTemplates());
    }
}
