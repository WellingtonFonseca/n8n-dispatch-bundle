<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Form\Extension;

use Mautic\CoreBundle\Service\FlashBag;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Form\Type\EmailType;
use MauticPlugin\N8nDispatchBundle\Form\Extension\EmailVariablePrefixExtension;
use MauticPlugin\N8nDispatchBundle\Service\TemplateVariableScanner;
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

class EmailVariablePrefixExtensionTest extends TestCase
{
    private TranslatorInterface&MockObject $translator;

    private FlashBag&MockObject $flashBag;

    private RequestStack $requestStack;

    protected function setUp(): void
    {
        $this->translator   = $this->createMock(TranslatorInterface::class);
        $this->flashBag     = $this->createMock(FlashBag::class);
        $this->requestStack = new RequestStack();
        $this->requestStack->push(Request::create('/s/emails/edit/1'));

        // Same wording as the alert on the edit page: the tab's name is a placeholder in the message.
        $this->translator->method('trans')->willReturnCallback(
            fn (string $key, array $params = []): string => 'mautic.n8ndispatch.email.tab.label' === $key
                ? 'Variables N8N'
                : $key.' '.json_encode($params)
        );
    }

    public function testExtendsTheEmailForm(): void
    {
        $this->assertSame([EmailType::class], EmailVariablePrefixExtension::getExtendedTypes());
    }

    public function testNoErrorWhenEveryVariableHasThePrefix(): void
    {
        $customHtml = $this->createMock(FormInterface::class);
        $customHtml->expects($this->never())->method('addError');
        $this->flashBag->expects($this->never())->method('add');

        $this->submit('<p>{{n8n_nome}} {{ n8n_curso }} <a href="{{n8ndispatch_unsubscribe_url}}">x</a></p>', $customHtml);
    }

    public function testNoErrorWhenTheTemplateHasNoHtml(): void
    {
        $customHtml = $this->createMock(FormInterface::class);
        $customHtml->expects($this->never())->method('addError');

        $this->submit(null, $customHtml);
    }

    public function testAddsAnErrorToCustomHtmlWithTheSameWordingAsTheAlert(): void
    {
        $customHtml = $this->createMock(FormInterface::class);
        $customHtml->expects($this->once())
            ->method('addError')
            ->with($this->callback(
                // Core's API error handling (FormErrorMessagesTrait::getFormErrorCodes) calls
                // $error->getCause()->getCode(), so the cause must be a violation, never null.
                fn (FormError $e): bool => 'mautic.n8ndispatch.email.alert.prefix {"%tab%":"Variables N8N","%prefix%":"n8n_"}' === $e->getMessage()
                    && $e->getCause() instanceof ConstraintViolationInterface
            ));

        $this->submit('<p>{{nome}} {{n8n_ok}} {{curso}} {{nome}}</p>', $customHtml);
    }

    public function testFlashesTheMessageWithTheTabInBoldForTheUiButNotForTheApi(): void
    {
        $this->flashBag->expects($this->once())
            ->method('add')
            ->with(
                'mautic.n8ndispatch.email.alert.prefix',
                ['%tab%' => '<b>Variables N8N</b>', '%prefix%' => 'n8n_'],
                FlashBag::LEVEL_ERROR,
                'messages'
            );

        $this->submit('{{nome}}', $this->createMock(FormInterface::class));

        $this->requestStack->push(Request::create('/api/emails/new', 'POST'));
        // Same expectation of ONE call overall: the API request must not add a second flash.
        $this->submit('{{nome}}', $this->createMock(FormInterface::class));
    }

    public function testNeverBlocksTheFirstSaveOfANewEmail(): void
    {
        // Templates often come from other tools that don't use the prefix; they get adjusted from the second save on.
        $customHtml = $this->createMock(FormInterface::class);
        $customHtml->expects($this->never())->method('addError');
        $this->flashBag->expects($this->never())->method('add');

        $this->submit('<p>{{nome}} {{curso}}</p>', $customHtml, null);
    }

    public function testIgnoresDataThatIsNotAnEmail(): void
    {
        $form = $this->createMock(FormInterface::class);
        $form->expects($this->never())->method('get');

        $this->listener()(new FormEvent($form, new \stdClass()));
    }

    /**
     * @param int|null $id null stands for an Email that was never saved (its first save)
     */
    private function submit(?string $html, FormInterface&MockObject $customHtml, ?int $id = 7): void
    {
        $email = $this->createMock(Email::class);
        $email->method('getId')->willReturn($id);
        $email->method('getCustomHtml')->willReturn($html);

        $form = $this->createMock(FormInterface::class);
        $form->method('get')->with('customHtml')->willReturn($customHtml);

        $this->listener()(new FormEvent($form, $email));
    }

    private function listener(): callable
    {
        $extension = new EmailVariablePrefixExtension(new TemplateVariableScanner(), $this->translator, $this->flashBag, $this->requestStack);

        $captured = null;
        $builder  = $this->createMock(FormBuilderInterface::class);
        $builder->expects($this->once())
            ->method('addEventListener')
            ->with(FormEvents::POST_SUBMIT, $this->callback(function ($callback) use (&$captured): bool {
                $captured = $callback;

                return true;
            }));

        $extension->buildForm($builder, []);

        return $captured;
    }
}
