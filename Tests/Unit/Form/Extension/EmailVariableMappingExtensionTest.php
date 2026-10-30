<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Form\Extension;

use PHPUnit\Framework\MockObject\Stub;
use Mautic\CoreBundle\Service\FlashBag;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Form\Type\EmailType;
use MauticPlugin\N8nDispatchBundle\Entity\EmailVariablesRepository;
use MauticPlugin\N8nDispatchBundle\Form\Extension\EmailVariableMappingExtension;
use MauticPlugin\N8nDispatchBundle\Service\TemplateVariableScanner;
use MauticPlugin\N8nDispatchBundle\Service\VariableMappingChecker;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Exception\LogicException;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormErrorIterator;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
class EmailVariableMappingExtensionTest extends TestCase
{
    private EmailVariablesRepository&MockObject $repository;

    private TranslatorInterface&Stub $translator;

    private FlashBag&MockObject $flashBag;

    private RequestStack $requestStack;

    protected function setUp(): void
    {
        $this->repository   = $this->createMock(EmailVariablesRepository::class);
        $this->translator   = $this->createStub(TranslatorInterface::class);
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

        $customHtml = $this->customHtml(1);
        $customHtml->expects($this->never())->method('addError');
        $this->flashBag->expects($this->never())->method('add');

        $this->submit(7, '<p>{{nome}}</p>', $customHtml);
    }

    public function testStillChecksWhenTheRequestLeavesCustomHtmlOut(): void
    {
        // An API PATCH of other fields never submits customHtml; the saved HTML is checked all the same.
        $this->repository->method('getVariablesJsonForEmail')->willReturn(null);

        $customHtml = $this->customHtml();
        $customHtml->expects($this->once())->method('addError');

        $this->submit(7, '<p>{{n8n_nome}}</p>', $customHtml);
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

    public function testBlocksAnEmailWhoseSubjectHasAnUnmappedVariable(): void
    {
        $this->repository->method('getVariablesJsonForEmail')->with(7)->willReturn(json_encode(['n8n_nome' => ['source' => 'static', 'value' => 'x']]));
        $customHtml = $this->customHtml();
        $customHtml->expects($this->never())->method('addError');
        $subject = $this->customHtml();
        $subject->expects($this->once())->method('addError')->with($this->callback(
            fn (FormError $e): bool => 'mautic.n8ndispatch.email.error.unmapped {"%tab%":"Variables N8N"}' === $e->getMessage()
                && $e->getCause() instanceof ConstraintViolationInterface
                && 'subject' === $e->getCause()->getPropertyPath()
                && 'Oi {{n8n_curso}}' === $e->getCause()->getInvalidValue()
        ));
        $this->flashBag->expects($this->once())->method('add');

        $this->submit(7, '<p>{{n8n_nome}}</p>', $customHtml, 'Oi {{n8n_curso}}', $subject);
    }

    public function testAllowsASubjectWhoseVariablesAreMapped(): void
    {
        $this->repository->method('getVariablesJsonForEmail')->willReturn(json_encode(['n8n_nome' => ['source' => 'static', 'value' => 'x']]));
        $customHtml = $this->customHtml();
        $customHtml->expects($this->never())->method('addError');
        $subject = $this->customHtml();
        $subject->expects($this->never())->method('addError');

        $this->submit(7, '<p>sem variavel</p>', $customHtml, 'Oi {{n8n_nome}}', $subject);
    }

    public function testTheErrorIsOnTheHtmlWhenItsVariableIsUnmappedEvenIfTheSubjectIsMapped(): void
    {
        $this->repository->method('getVariablesJsonForEmail')->willReturn(json_encode(['n8n_nome' => ['source' => 'static', 'value' => 'x']]));
        $customHtml = $this->customHtml();
        $customHtml->expects($this->once())->method('addError')->with($this->callback(fn (FormError $e): bool => 'customHtml' === $e->getCause()->getPropertyPath()));
        $subject = $this->customHtml();
        $subject->expects($this->never())->method('addError');

        $this->submit(7, '<p>{{n8n_curso}}</p>', $customHtml, 'Oi {{n8n_nome}}', $subject);
    }

    public function testOneErrorOnTheHtmlWhenBothHaveAnUnmappedVariable(): void
    {
        $this->repository->method('getVariablesJsonForEmail')->willReturn(null);
        $customHtml = $this->customHtml();
        $customHtml->expects($this->once())->method('addError');
        $subject = $this->customHtml();
        $subject->expects($this->never())->method('addError');
        $this->flashBag->expects($this->once())->method('add');

        $this->submit(7, '<p>{{n8n_a}}</p>', $customHtml, 'Oi {{n8n_b}}', $subject);
    }

    public function testLeavesItToThePrefixCheckWhenTheSubjectAlreadyHasAnError(): void
    {
        $this->repository->expects($this->never())->method('getVariablesJsonForEmail');
        $customHtml = $this->customHtml();
        $customHtml->expects($this->never())->method('addError');
        $subject = $this->customHtml(1);
        $subject->expects($this->never())->method('addError');
        $this->flashBag->expects($this->never())->method('add');

        $this->submit(7, '<p>ok</p>', $customHtml, 'Oi {{nome}}', $subject);
    }

    public function testASubjectWithoutVariablesNeverLooksAtTheSubjectField(): void
    {
        $this->repository->method('getVariablesJsonForEmail')->willReturn(null);
        $customHtml = $this->customHtml();
        $customHtml->expects($this->once())->method('addError');
        $subject = $this->createMock(FormInterface::class);
        $subject->expects($this->never())->method('getErrors');
        $subject->expects($this->never())->method('addError');

        $this->submit(7, '<p>{{n8n_nome}}</p>', $customHtml, 'Oi {contactfield=firstname}', $subject);
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

    /**
     * Like the real field when the request leaves customHtml out (an API PATCH of other fields): never submitted,
     * so isValid() throws; getErrors() is the call that works either way.
     */
    private function customHtml(int $errors = 0): FormInterface&MockObject
    {
        $iterator = $this->createStub(FormErrorIterator::class);
        $iterator->method('count')->willReturn($errors);

        $customHtml = $this->createMock(FormInterface::class);
        $customHtml->method('getErrors')->willReturn($iterator);
        $customHtml->method('isValid')->willThrowException(new LogicException('Cannot check if an unsubmitted form is valid.'));

        return $customHtml;
    }

    private function submit(?int $id, string $html, FormInterface $customHtml, ?string $subject = null, ?FormInterface $subjectField = null): void
    {
        $email = $this->createMock(Email::class);
        $email->method('getId')->willReturn($id);
        $email->method('getCustomHtml')->willReturn($html);
        $email->method('getSubject')->willReturn($subject);

        $form = $this->createMock(FormInterface::class);
        $form->method('get')->willReturnMap([['customHtml', $customHtml], ['subject', $subjectField ?? $this->customHtml()]]);

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
