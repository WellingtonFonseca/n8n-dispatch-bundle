<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Form\Extension;

use Mautic\CoreBundle\Service\FlashBag;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Form\Type\EmailType;
use MauticPlugin\N8nDispatchBundle\Entity\EmailVariablesRepository;
use MauticPlugin\N8nDispatchBundle\Form\Extension\EmailVariableMappingExtension;
use MauticPlugin\N8nDispatchBundle\Service\TemplateVariableScanner;
use MauticPlugin\N8nDispatchBundle\Service\VariableMappingChecker;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class EmailVariableMappingExtensionTest extends TestCase
{
    private EmailVariablesRepository&MockObject $repository;

    private TranslatorInterface&MockObject $translator;

    private FlashBag&MockObject $flashBag;

    private RequestStack $requestStack;

    protected function setUp(): void
    {
        $this->repository   = $this->createMock(EmailVariablesRepository::class);
        $this->translator   = $this->createMock(TranslatorInterface::class);
        $this->flashBag     = $this->createMock(FlashBag::class);
        $this->requestStack = new RequestStack();
        $this->requestStack->push(Request::create('/s/emails/edit/7', 'POST'));

        $this->translator->method('trans')->willReturnCallback(
            fn (string $key, array $params = []): string => 'mautic.n8ndispatch.email.tab.label' === $key
                ? 'Variables N8N'
                : $key.' '.json_encode($params)
        );
    }

    public function testExtendsTheEmailForm(): void
    {
        $this->assertSame([EmailType::class], EmailVariableMappingExtension::getExtendedTypes());
    }

    public function testNeverBlocksTheFirstSaveOfANewEmail(): void
    {
        $this->repository->expects($this->never())->method('getVariablesJsonForEmail');
        $customHtml = $this->customHtml();
        $customHtml->expects($this->never())->method('addError');
        $this->flashBag->expects($this->never())->method('add');

        $this->submit(null, '<p>{{n8n_nome}}</p>', $customHtml);
    }

    public function testBlocksAnExistingEmailWithAnUnmappedVariable(): void
    {
        $this->repository->method('getVariablesJsonForEmail')->with(7)->willReturn(null);

        $customHtml = $this->customHtml();
        $customHtml->expects($this->once())
            ->method('addError')
            ->with($this->callback(
                fn (FormError $e): bool => 'mautic.n8ndispatch.email.error.unmapped {"%tab%":"Variables N8N"}' === $e->getMessage()
                    && $e->getCause() instanceof ConstraintViolationInterface
            ));

        $this->flashBag->expects($this->once())
            ->method('add')
            ->with('mautic.n8ndispatch.email.error.unmapped', ['%tab%' => '<b>Variables N8N</b>'], FlashBag::LEVEL_ERROR, 'messages');

        $this->submit(7, '<p>{{n8n_nome}}</p>', $customHtml);
    }

    public function testBlocksWhenAMappedVariableWasLeftBlank(): void
    {
        $this->repository->method('getVariablesJsonForEmail')->willReturn(json_encode([
            'n8n_nome'  => ['source' => 'field', 'field' => 'firstname'],
            'n8n_curso' => ['source' => 'static', 'value' => ''],
        ]));

        $customHtml = $this->customHtml();
        $customHtml->expects($this->once())->method('addError');

        $this->submit(7, '<p>{{n8n_nome}} {{n8n_curso}}</p>', $customHtml);
    }

    public function testTreatsAnInvalidMappingAsEmpty(): void
    {
        $this->repository->method('getVariablesJsonForEmail')->willReturn('not json');

        $customHtml = $this->customHtml();
        $customHtml->expects($this->once())->method('addError');

        $this->submit(7, '<p>{{n8n_nome}}</p>', $customHtml);
    }

    public function testAllowsAnExistingEmailWhenEveryVariableIsMapped(): void
    {
        $this->repository->method('getVariablesJsonForEmail')->willReturn(json_encode([
            'n8n_nome' => ['source' => 'static', 'value' => 'Maria'],
        ]));

        $customHtml = $this->customHtml();
        $customHtml->expects($this->never())->method('addError');
        $this->flashBag->expects($this->never())->method('add');

        $this->submit(7, '<p>{{n8n_nome}}</p>', $customHtml);
    }

    public function testAllowsAnExistingEmailWithoutVariables(): void
    {
        $this->repository->expects($this->never())->method('getVariablesJsonForEmail');
        $customHtml = $this->customHtml();
        $customHtml->expects($this->never())->method('addError');

        $this->submit(7, '<p>Hello</p>', $customHtml);
    }

    public function testIgnoresTheReservedUnsubscribeVariable(): void
    {
        $customHtml = $this->customHtml();
        $customHtml->expects($this->never())->method('addError');

        $this->submit(7, '<a href="{{n8ndispatch_unsubscribe_url}}">x</a>', $customHtml);
    }

    public function testLeavesItToThePrefixCheckWhenCustomHtmlAlreadyHasAnError(): void
    {
        $this->repository->expects($this->never())->method('getVariablesJsonForEmail');

        $customHtml = $this->createMock(FormInterface::class);
        $customHtml->method('isValid')->willReturn(false);
        $customHtml->expects($this->never())->method('addError');
        $this->flashBag->expects($this->never())->method('add');

        $this->submit(7, '<p>{{nome}}</p>', $customHtml);
    }

    public function testFlashesOnlyForTheUiNotForTheApi(): void
    {
        $this->repository->method('getVariablesJsonForEmail')->willReturn(null);

        $this->requestStack->pop();
        $this->requestStack->push(Request::create('/api/emails/7/edit', 'PATCH'));

        $customHtml = $this->customHtml();
        $customHtml->expects($this->once())->method('addError');
        $this->flashBag->expects($this->never())->method('add');

        $this->submit(7, '<p>{{n8n_nome}}</p>', $customHtml);
    }

    public function testIgnoresDataThatIsNotAnEmail(): void
    {
        $form = $this->createMock(FormInterface::class);
        $form->expects($this->never())->method('get');

        $this->listener()(new FormEvent($form, new \stdClass()));
    }

    public function testRunsAfterThePrefixCheck(): void
    {
        $extension = $this->extension();
        $builder   = $this->createMock(FormBuilderInterface::class);
        $builder->expects($this->once())
            ->method('addEventListener')
            ->with(FormEvents::POST_SUBMIT, $this->anything(), -10);

        $extension->buildForm($builder, []);
    }

    private function customHtml(): FormInterface&MockObject
    {
        $customHtml = $this->createMock(FormInterface::class);
        $customHtml->method('isValid')->willReturn(true);

        return $customHtml;
    }

    private function submit(?int $id, string $html, FormInterface $customHtml): void
    {
        $email = $this->createMock(Email::class);
        $email->method('getId')->willReturn($id);
        $email->method('getCustomHtml')->willReturn($html);

        $form = $this->createMock(FormInterface::class);
        $form->method('get')->with('customHtml')->willReturn($customHtml);

        $this->listener()(new FormEvent($form, $email));
    }

    private function extension(): EmailVariableMappingExtension
    {
        return new EmailVariableMappingExtension(
            new TemplateVariableScanner(),
            new VariableMappingChecker(),
            $this->repository,
            $this->translator,
            $this->flashBag,
            $this->requestStack
        );
    }

    private function listener(): callable
    {
        $captured = null;
        $builder  = $this->createMock(FormBuilderInterface::class);
        $builder->method('addEventListener')->willReturnCallback(function (string $event, callable $callback) use (&$captured, $builder) {
            $captured = $callback;

            return $builder;
        });

        $this->extension()->buildForm($builder, []);

        return $captured;
    }
}
