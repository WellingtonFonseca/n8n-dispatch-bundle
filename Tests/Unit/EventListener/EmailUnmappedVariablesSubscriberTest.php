<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\EventListener;

use Mautic\CoreBundle\Service\FlashBag;
use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Event\EmailEvent;
use MauticPlugin\N8nDispatchBundle\Entity\EmailVariablesRepository;
use MauticPlugin\N8nDispatchBundle\EventListener\EmailUnmappedVariablesSubscriber;
use MauticPlugin\N8nDispatchBundle\Service\TemplateVariableScanner;
use MauticPlugin\N8nDispatchBundle\Service\VariableMappingChecker;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

class EmailUnmappedVariablesSubscriberTest extends TestCase
{
    private EmailVariablesRepository&MockObject $repository;

    private FlashBag&MockObject $flashBag;

    private TranslatorInterface&MockObject $translator;

    private RequestStack $requestStack;

    protected function setUp(): void
    {
        $this->repository   = $this->createMock(EmailVariablesRepository::class);
        $this->flashBag     = $this->createMock(FlashBag::class);
        $this->translator   = $this->createMock(TranslatorInterface::class);
        $this->requestStack = new RequestStack();
        // The Email edit screen posts its fields as emailform[...]; the API does not.
        $this->requestStack->push(new Request([], ['emailform' => ['name' => 'x']], [], [], [], ['REQUEST_METHOD' => 'POST']));
    }

    public function testListensToThePostSaveEvent(): void
    {
        $this->assertArrayHasKey(EmailEvents::EMAIL_POST_SAVE, EmailUnmappedVariablesSubscriber::getSubscribedEvents());
    }

    public function testWarnsWhenAVariableHasNoMapping(): void
    {
        $this->repository->method('getVariablesJsonForEmail')->with(7)->willReturn(null);
        $this->translator->method('trans')->with('mautic.n8ndispatch.email.tab.label')->willReturn('Variáveis N8N');

        $this->flashBag->expects($this->once())
            ->method('add')
            ->with(
                'mautic.n8ndispatch.email.warning.unmapped_after_save',
                ['%tab%' => '<b>Variáveis N8N</b>'],
                FlashBag::LEVEL_WARNING,
                'messages'
            );

        $this->save('<p>{{n8n_nome}}</p>');
    }

    public function testWarnsWhenAMappedVariableWasLeftBlank(): void
    {
        $this->repository->method('getVariablesJsonForEmail')->willReturn(json_encode([
            'n8n_nome'  => ['source' => 'field', 'field' => 'firstname'],
            'n8n_curso' => ['source' => 'static', 'value' => ''],
        ]));
        $this->translator->method('trans')->willReturn('Tab');

        $this->flashBag->expects($this->once())->method('add');

        $this->save('<p>{{n8n_nome}} {{n8n_curso}}</p>');
    }

    public function testStaysQuietWhenEveryVariableIsMapped(): void
    {
        $this->repository->method('getVariablesJsonForEmail')->willReturn(json_encode([
            'n8n_nome' => ['source' => 'static', 'value' => 'Maria'],
        ]));

        $this->flashBag->expects($this->never())->method('add');

        $this->save('<p>{{n8n_nome}}</p>');
    }

    public function testStaysQuietWhenTheTemplateHasNoVariables(): void
    {
        $this->repository->expects($this->never())->method('getVariablesJsonForEmail');
        $this->flashBag->expects($this->never())->method('add');

        $this->save('<p>Hello</p>');
    }

    public function testIgnoresTheReservedUnsubscribeVariable(): void
    {
        $this->flashBag->expects($this->never())->method('add');

        $this->save('<a href="{{n8ndispatch_unsubscribe_url}}">Unsubscribe</a>');
    }

    public function testTreatsAnInvalidMappingAsEmpty(): void
    {
        $this->repository->method('getVariablesJsonForEmail')->willReturn('not json');
        $this->translator->method('trans')->willReturn('Tab');

        $this->flashBag->expects($this->once())->method('add');

        $this->save('<p>{{n8n_nome}}</p>');
    }

    public function testDoesNothingOutsideTheEmailEditForm(): void
    {
        $this->requestStack->pop();
        $this->requestStack->push(Request::create('/api/emails/new', 'POST', [], [], [], [], '{"name":"x"}'));

        $this->flashBag->expects($this->never())->method('add');

        $this->save('<p>{{n8n_nome}}</p>');
    }

    public function testDoesNothingWithoutARequest(): void
    {
        $this->requestStack->pop();

        $this->flashBag->expects($this->never())->method('add');

        $this->save('<p>{{n8n_nome}}</p>');
    }

    public function testWarnsOnlyOncePerRequest(): void
    {
        $this->repository->method('getVariablesJsonForEmail')->willReturn(null);
        $this->translator->method('trans')->willReturn('Tab');

        $this->flashBag->expects($this->once())->method('add');

        $subscriber = $this->subscriber();
        $subscriber->onEmailPostSave($this->event('<p>{{n8n_nome}}</p>'));
        $subscriber->onEmailPostSave($this->event('<p>{{n8n_nome}}</p>'));
    }

    private function save(string $html): void
    {
        $this->subscriber()->onEmailPostSave($this->event($html));
    }

    private function event(string $html): EmailEvent
    {
        $email = $this->createMock(Email::class);
        $email->method('getId')->willReturn(7);
        $email->method('getCustomHtml')->willReturn($html);

        return new EmailEvent($email);
    }

    private function subscriber(): EmailUnmappedVariablesSubscriber
    {
        return new EmailUnmappedVariablesSubscriber(
            new TemplateVariableScanner(),
            new VariableMappingChecker(),
            $this->repository,
            $this->translator,
            $this->flashBag,
            $this->requestStack
        );
    }
}
