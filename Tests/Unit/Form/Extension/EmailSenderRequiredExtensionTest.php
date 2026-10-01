<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Tests\Unit\Form\Extension;

use Mautic\CoreBundle\Service\FlashBag;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Form\Type\EmailType;
use MauticPlugin\N8nDispatchBundle\Form\Extension\EmailSenderRequiredExtension;
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

class EmailSenderRequiredExtensionTest extends TestCase
{
    private TranslatorInterface&MockObject $translator;

    private FlashBag&MockObject $flashBag;

    private RequestStack $requestStack;

    protected function setUp(): void
    {
        $this->translator   = $this->createMock(TranslatorInterface::class);
        $this->flashBag     = $this->createMock(FlashBag::class);
        $this->requestStack = new RequestStack();
        $this->requestStack->push(Request::create('/s/emails/edit/7', 'POST'));

        // The tab's name and the fields' labels come from core's own translations, the ones that draw the screen,
        // so the message always matches what the user sees in the installed language.
        $labels = [
            'mautic.core.advanced'    => 'Advanced',
            'mautic.email.from_name'  => 'From name',
            'mautic.email.from_email' => 'From address',
        ];
        $this->translator->method('trans')->willReturnCallback(
            fn (string $key, array $params = []): string => $labels[$key] ?? $key.' '.json_encode($params)
        );
    }

    public function testExtendsTheEmailForm(): void
    {
        $this->assertSame([EmailType::class], EmailSenderRequiredExtension::getExtendedTypes());
    }

    public function testNoErrorWhenBothSenderFieldsAreFilled(): void
    {
        $fromName = $this->field();
        $fromName->expects($this->never())->method('addError');
        $fromAddress = $this->field();
        $fromAddress->expects($this->never())->method('addError');
        $this->flashBag->expects($this->never())->method('add');

        $this->submit('Institution', 'no-reply@example.com', $fromName, $fromAddress);
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function blankValues(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'spaces' => ['   '];
    }

    /**
     * @dataProvider blankValues
     */
    public function testBlocksWhenTheFromNameIsBlank(?string $blank): void
    {
        $fromName = $this->field();
        $fromName->expects($this->once())->method('addError')->with($this->errorWith('mautic.n8ndispatch.email.error.sender_required {"%field%":"From name","%tab%":"Advanced"}'));
        $fromAddress = $this->field();
        $fromAddress->expects($this->never())->method('addError');

        $this->submit($blank, 'no-reply@example.com', $fromName, $fromAddress);
    }

    /**
     * @dataProvider blankValues
     */
    public function testBlocksWhenTheFromAddressIsBlank(?string $blank): void
    {
        $fromName = $this->field();
        $fromName->expects($this->never())->method('addError');
        $fromAddress = $this->field();
        $fromAddress->expects($this->once())->method('addError')->with($this->errorWith('mautic.n8ndispatch.email.error.sender_required {"%field%":"From address","%tab%":"Advanced"}'));

        $this->submit('Institution', $blank, $fromName, $fromAddress);
    }

    public function testReportsBothFieldsWhenBothAreBlank(): void
    {
        $fromName = $this->field();
        $fromName->expects($this->once())->method('addError');
        $fromAddress = $this->field();
        $fromAddress->expects($this->once())->method('addError');

        $this->submit(null, null, $fromName, $fromAddress);
    }

    public function testAlsoBlocksTheFirstSaveOfANewEmail(): void
    {
        $fromName = $this->field();
        $fromName->expects($this->once())->method('addError');
        $fromAddress = $this->field();
        $fromAddress->expects($this->once())->method('addError');

        $this->submit(null, null, $fromName, $fromAddress, null);
    }

    public function testFlashesEachMessageForTheUiButNotForTheApi(): void
    {
        $flashed = [];
        $this->flashBag->expects($this->exactly(2))
            ->method('add')
            ->willReturnCallback(function (string $message, array $vars, string $level, string $domain) use (&$flashed): void {
                $this->assertSame('mautic.n8ndispatch.email.error.sender_required', $message);
                $this->assertSame(FlashBag::LEVEL_ERROR, $level);
                $this->assertSame('messages', $domain);
                // The tab is in bold on screen (plain text in the form error, which is what the API returns).
                $this->assertSame('<b>Advanced</b>', $vars['%tab%']);
                $flashed[] = $vars['%field%'];
            });

        $this->submit(null, null, $this->field(), $this->field());

        $this->requestStack->pop();
        $this->requestStack->push(Request::create('/api/emails/new', 'POST'));
        // Still only the two flashes from the UI request above: the API request adds none.
        $this->submit(null, null, $this->field(), $this->field());

        $this->assertSame(['From name', 'From address'], $flashed);
    }

    public function testIgnoresDataThatIsNotAnEmail(): void
    {
        $form = $this->createMock(FormInterface::class);
        $form->expects($this->never())->method('get');

        $this->listener()(new FormEvent($form, new \stdClass()));
    }

    private function field(): FormInterface&MockObject
    {
        return $this->createMock(FormInterface::class);
    }

    /**
     * Core's API error handling reads $error->getCause()->getCode(), so the cause must be a violation, never null.
     */
    private function errorWith(string $message): \PHPUnit\Framework\Constraint\Callback
    {
        return $this->callback(
            fn (FormError $e): bool => $message === $e->getMessage() && $e->getCause() instanceof ConstraintViolationInterface
        );
    }

    private function submit(?string $fromName, ?string $fromAddress, FormInterface $fromNameField, FormInterface $fromAddressField, ?int $id = 7): void
    {
        $email = $this->createMock(Email::class);
        $email->method('getId')->willReturn($id);
        $email->method('getFromName')->willReturn($fromName);
        $email->method('getFromAddress')->willReturn($fromAddress);

        $form = $this->createMock(FormInterface::class);
        $form->method('get')->willReturnMap([
            ['fromName', $fromNameField],
            ['fromAddress', $fromAddressField],
        ]);

        $this->listener()(new FormEvent($form, $email));
    }

    private function listener(): callable
    {
        $extension = new EmailSenderRequiredExtension($this->translator, $this->flashBag, $this->requestStack);

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
