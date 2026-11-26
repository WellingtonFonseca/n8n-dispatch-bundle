<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Form\Extension;

use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Form\Type\EmailType;
use MauticPlugin\N8nDispatchBundle\Entity\EmailVariables;
use MauticPlugin\N8nDispatchBundle\Entity\EmailVariablesRepository;
use MauticPlugin\N8nDispatchBundle\Form\Extension\EmailProviderExtension;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The "Enviar por" select of the Email edit page: n8n by default, the value only handed over by a valid form,
 * and kept per Email so a batch request never mixes them.
 */
#[Group('area:email')]
#[Group('area:dispatch-email')]
#[Group('risk:FLUXO')]
#[Group('risk:DADOS')]
class EmailProviderExtensionTest extends TestCase
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

    private function extension(): EmailProviderExtension
    {
        return new EmailProviderExtension($this->repository, $this->requestStack);
    }

    private function email(?int $id): Email
    {
        $email = $this->createMock(Email::class);
        $email->method('getId')->willReturn($id);

        return $email;
    }

    /**
     * @return array{0: array<string, mixed>, 1: callable}
     */
    private function build(?Email $email): array
    {
        $options  = null;
        $listener = null;
        $builder  = $this->createMock(FormBuilderInterface::class);
        $builder->method('add')->willReturnCallback(function (string $name, string $type, array $o) use (&$options, $builder) {
            $options = $o;

            return $builder;
        });
        $builder->method('addEventListener')->willReturnCallback(function (string $event, callable $l, int $priority) use (&$listener, $builder) {
            $this->assertSame(FormEvents::POST_SUBMIT, $event);
            $this->assertLessThan(0, $priority, 'must run after the validator, so only a valid form hands the value over');
            $listener = $l;

            return $builder;
        });

        $this->extension()->buildForm($builder, ['data' => $email]);

        return [$options, $listener];
    }

    private function submit(callable $listener, Email $email, bool $valid, bool $submitted, mixed $value): void
    {
        $field = $this->createMock(FormInterface::class);
        $field->method('isSubmitted')->willReturn($submitted);
        $field->method('getData')->willReturn($value);
        $form = $this->createMock(FormInterface::class);
        $form->method('getData')->willReturn($email);
        $form->method('isValid')->willReturn($valid);
        $form->method('get')->with(EmailProviderExtension::FIELD)->willReturn($field);

        $listener(new FormEvent($form, $email));
    }

    public function testExtendsTheEmailForm(): void
    {
        $this->assertSame([EmailType::class], EmailProviderExtension::getExtendedTypes());
    }

    public function testANewEmailDefaultsToN8nAndOffersBothProviders(): void
    {
        $this->repository->expects($this->never())->method('getProviderForEmail');

        [$options] = $this->build($this->email(null));

        $this->assertSame(EmailVariables::PROVIDER_N8N, $options['data']);
        $this->assertFalse($options['mapped']);
        $this->assertSame([EmailVariables::PROVIDER_N8N, EmailVariables::PROVIDER_MAUTIC], array_values($options['choices']));
    }

    public function testAnExistingEmailShowsItsStoredProvider(): void
    {
        $this->repository->expects($this->once())->method('getProviderForEmail')->with(7)->willReturn(EmailVariables::PROVIDER_MAUTIC);

        [$options] = $this->build($this->email(7));

        $this->assertSame(EmailVariables::PROVIDER_MAUTIC, $options['data']);
    }

    public function testAValidSubmitKeepsTheValueOnTheRequestForThatEmail(): void
    {
        [, $listener] = $this->build($this->email(7));
        $email        = $this->email(7);

        $this->submit($listener, $email, true, true, EmailVariables::PROVIDER_MAUTIC);

        $this->assertSame(
            [spl_object_id($email) => EmailVariables::PROVIDER_MAUTIC],
            $this->request->attributes->get(EmailProviderExtension::REQUEST_ATTRIBUTE)
        );
    }

    public function testTwoEmailsOfTheSameRequestKeepEachItsOwnValue(): void
    {
        [, $listener] = $this->build($this->email(1));
        $first        = $this->email(1);
        $second       = $this->email(2);

        $this->submit($listener, $first, true, true, EmailVariables::PROVIDER_MAUTIC);
        $this->submit($listener, $second, true, true, EmailVariables::PROVIDER_N8N);

        $this->assertSame(
            [spl_object_id($first) => EmailVariables::PROVIDER_MAUTIC, spl_object_id($second) => EmailVariables::PROVIDER_N8N],
            $this->request->attributes->get(EmailProviderExtension::REQUEST_ATTRIBUTE)
        );
    }

    /**
     * @dataProvider notHandedOver
     */
    public function testNothingIsKeptWhenTheFormIsInvalidOrTheFieldWasNotSent(bool $valid, bool $submitted, mixed $value): void
    {
        [, $listener] = $this->build($this->email(7));

        $this->submit($listener, $this->email(7), $valid, $submitted, $value);

        $this->assertFalse($this->request->attributes->has(EmailProviderExtension::REQUEST_ATTRIBUTE));
    }

    /**
     * @return iterable<string, array{bool, bool, mixed}>
     */
    public static function notHandedOver(): iterable
    {
        yield 'invalid form' => [false, true, EmailVariables::PROVIDER_MAUTIC];
        yield 'field not sent (API of other fields)' => [true, false, null];
        yield 'empty value' => [true, true, ''];
        yield 'not a string' => [true, true, ['mautic']];
    }

    public function testNothingIsKeptWithoutARequest(): void
    {
        $this->requestStack = new RequestStack();
        [, $listener]       = $this->build($this->email(7));

        $this->submit($listener, $this->email(7), true, true, EmailVariables::PROVIDER_MAUTIC);

        $this->assertFalse($this->request->attributes->has(EmailProviderExtension::REQUEST_ATTRIBUTE));
    }
}
