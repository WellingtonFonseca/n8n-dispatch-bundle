<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\EventListener;

use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Event\EmailEvent;
use MauticPlugin\N8nDispatchBundle\Entity\EmailVariables;
use MauticPlugin\N8nDispatchBundle\Entity\EmailVariablesRepository;
use MauticPlugin\N8nDispatchBundle\EventListener\EmailProviderSaveSubscriber;
use MauticPlugin\N8nDispatchBundle\Form\Extension\EmailProviderExtension;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Writes the "Enviar por" of the Email form once the Email has its id; each value is used by its own Email only.
 */
#[Group('area:email')]
#[Group('area:dispatch-email')]
#[Group('risk:DADOS')]
#[Group('risk:FLUXO')]
class EmailProviderSaveSubscriberTest extends TestCase
{
    private EmailVariablesRepository&MockObject $repository;

    private RequestStack $requestStack;

    private Request $request;

    protected function setUp(): void
    {
        $this->repository   = $this->createMock(EmailVariablesRepository::class);
        $this->requestStack = new RequestStack();
        $this->request      = Request::create('/s/emails/edit/7', 'POST');
        $this->requestStack->push($this->request);
    }

    private function email(?int $id): Email
    {
        $email = $this->createMock(Email::class);
        $email->method('getId')->willReturn($id);

        return $email;
    }

    private function event(Email $email): EmailEvent
    {
        $event = $this->createMock(EmailEvent::class);
        $event->method('getEmail')->willReturn($email);

        return $event;
    }

    private function subscriber(): EmailProviderSaveSubscriber
    {
        return new EmailProviderSaveSubscriber($this->repository, $this->requestStack);
    }

    public function testListensToThePostSaveOfTheEmail(): void
    {
        $this->assertSame([EmailEvents::EMAIL_POST_SAVE => 'onEmailPostSave'], EmailProviderSaveSubscriber::getSubscribedEvents());
    }

    public function testItWritesTheValueOfThisEmailAndForgetsItOnly(): void
    {
        $mine  = $this->email(7);
        $other = $this->email(8);
        $this->request->attributes->set(EmailProviderExtension::REQUEST_ATTRIBUTE, [
            spl_object_id($mine)  => EmailVariables::PROVIDER_MAUTIC,
            spl_object_id($other) => EmailVariables::PROVIDER_N8N,
        ]);
        $this->repository->expects($this->once())->method('saveProviderForEmail')->with(7, EmailVariables::PROVIDER_MAUTIC);

        $this->subscriber()->onEmailPostSave($this->event($mine));

        $this->assertSame(
            [spl_object_id($other) => EmailVariables::PROVIDER_N8N],
            $this->request->attributes->get(EmailProviderExtension::REQUEST_ATTRIBUTE)
        );
    }

    public function testTheValueIsUsedOnce(): void
    {
        $mine = $this->email(7);
        $this->request->attributes->set(EmailProviderExtension::REQUEST_ATTRIBUTE, [spl_object_id($mine) => EmailVariables::PROVIDER_MAUTIC]);
        $this->repository->expects($this->once())->method('saveProviderForEmail');

        $this->subscriber()->onEmailPostSave($this->event($mine));
        $this->subscriber()->onEmailPostSave($this->event($mine));
    }

    public function testAnEmailWithoutAValueOfItsOwnWritesNothing(): void
    {
        $this->request->attributes->set(EmailProviderExtension::REQUEST_ATTRIBUTE, [spl_object_id($this->email(1)) => EmailVariables::PROVIDER_MAUTIC]);
        $this->repository->expects($this->never())->method('saveProviderForEmail');

        $this->subscriber()->onEmailPostSave($this->event($this->email(9)));
    }

    public function testNothingIsWrittenWhenTheRequestSentNoField(): void
    {
        $this->repository->expects($this->never())->method('saveProviderForEmail');

        $this->subscriber()->onEmailPostSave($this->event($this->email(7)));
    }

    public function testNothingIsWrittenBeforeTheEmailHasAnId(): void
    {
        $email = $this->email(null);
        $this->request->attributes->set(EmailProviderExtension::REQUEST_ATTRIBUTE, [spl_object_id($email) => EmailVariables::PROVIDER_MAUTIC]);
        $this->repository->expects($this->never())->method('saveProviderForEmail');

        $this->subscriber()->onEmailPostSave($this->event($email));
    }

    public function testNothingIsWrittenWithoutARequest(): void
    {
        $this->requestStack = new RequestStack();
        $this->repository->expects($this->never())->method('saveProviderForEmail');

        $this->subscriber()->onEmailPostSave($this->event($this->email(7)));
    }
}
