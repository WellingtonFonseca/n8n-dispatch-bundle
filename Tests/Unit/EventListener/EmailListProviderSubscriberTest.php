<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\EventListener;

use Mautic\CoreBundle\CoreEvents;
use Mautic\CoreBundle\Event\CustomContentEvent;
use Mautic\EmailBundle\Entity\Email;
use MauticPlugin\N8nDispatchBundle\Entity\EmailVariablesRepository;
use MauticPlugin\N8nDispatchBundle\EventListener\EmailListProviderSubscriber;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The hidden marker each Email list row carries so the "Enviar por" column can be built: provider, header and label.
 */
#[Group('area:email')]
#[Group('risk:UI')]
#[Group('risk:DADOS')]
class EmailListProviderSubscriberTest extends TestCase
{
    /**
     * @param int[] $mauticIds ids the repository reports as sent through Mautic
     */
    private function subscriber(array $mauticIds = [], ?EmailVariablesRepository $repository = null): EmailListProviderSubscriber
    {
        if (null === $repository) {
            $repository = $this->createStub(EmailVariablesRepository::class);
            $repository->method('getMauticEmailIds')->willReturn(array_fill_keys($mauticIds, true));
        }
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $key): string => 'T:'.$key);

        return new EmailListProviderSubscriber($repository, $translator);
    }

    private function email(?int $id): Email
    {
        $email = new Email();
        if (null !== $id) {
            (new \ReflectionProperty(Email::class, 'id'))->setValue($email, $id);
        }

        return $email;
    }

    public function testListensToTheCustomContentEvent(): void
    {
        $this->assertArrayHasKey(CoreEvents::VIEW_INJECT_CUSTOM_CONTENT, EmailListProviderSubscriber::getSubscribedEvents());
    }

    public function testPrintsTheMarkerWithProviderHeaderAndLabelInTheNameCell(): void
    {
        $event = new CustomContentEvent('some.view', 'email.name', ['item' => $this->email(5)]);

        $this->subscriber([5])->injectViewCustomContent($event);

        $this->assertSame(
            ['<span class="n8ndispatch-email-provider" hidden data-provider="mautic" data-header="T:mautic.n8ndispatch.email.provider.label" data-label="T:mautic.n8ndispatch.email.provider.mautic"></span>'],
            $event->getContent()
        );
    }

    public function testAnEmailOutsideTheMauticSetReadsAsN8n(): void
    {
        $event = new CustomContentEvent('some.view', 'email.name', ['item' => $this->email(5)]);

        $this->subscriber([99])->injectViewCustomContent($event);

        $this->assertStringContainsString('data-provider="n8n"', implode('', $event->getContent()));
        $this->assertStringContainsString('T:mautic.n8ndispatch.email.provider.n8n', implode('', $event->getContent()));
    }

    public function testOtherContextsItemsAndUnsavedEmailsGetNothing(): void
    {
        $subscriber = $this->subscriber();
        $cases      = [
            new CustomContentEvent('some.view', 'page.header.left', ['item' => $this->email(5)]),
            new CustomContentEvent('some.view', 'email.name', []),
            new CustomContentEvent('some.view', 'email.name', ['item' => new \stdClass()]),
            new CustomContentEvent('some.view', 'email.name', ['item' => $this->email(null)]),
        ];
        foreach ($cases as $event) {
            $subscriber->injectViewCustomContent($event);
            $this->assertSame([], $event->getContent());
        }
    }

    public function testAnEmptySetMakesEveryEmailN8n(): void
    {
        $subscriber = $this->subscriber([]);
        foreach ([1, 5, 77] as $id) {
            $event = new CustomContentEvent('some.view', 'email.name', ['item' => $this->email($id)]);
            $subscriber->injectViewCustomContent($event);
            $this->assertStringContainsString('data-provider="n8n"', implode('', $event->getContent()));
        }
    }

    public function testManyRowsCostOneProviderQueryAndKeepTheirOwnProvider(): void
    {
        $repository = $this->createMock(EmailVariablesRepository::class);
        $repository->expects($this->once())->method('getMauticEmailIds')->willReturn([2 => true, 4 => true]);
        $repository->expects($this->never())->method('getProviderForEmail');
        $subscriber = $this->subscriber([], $repository);

        $seen = [];
        foreach ([1, 2, 3, 4, 5] as $id) {
            $event = new CustomContentEvent('some.view', 'email.name', ['item' => $this->email($id)]);
            $subscriber->injectViewCustomContent($event);
            preg_match('/data-provider="(\w+)"/', implode('', $event->getContent()), $m);
            $seen[$id] = $m[1];
        }

        $this->assertSame([1 => 'n8n', 2 => 'mautic', 3 => 'n8n', 4 => 'mautic', 5 => 'n8n'], $seen);
    }
}
